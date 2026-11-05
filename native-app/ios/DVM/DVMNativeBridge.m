#import "DVMNativeBridge.h"
#import <UserNotifications/UserNotifications.h>
#import <math.h>

@interface DVMNativeTransfer : NSObject
@property(nonatomic,strong) NSURL *file;
@property(nonatomic,strong) NSFileHandle *handle;
@property(nonatomic,copy) NSString *mime;
@property(nonatomic,assign) NSUInteger expected, received, sequence;
@end
@implementation DVMNativeTransfer
@end

@interface DVMNativeBridge ()
@property(nonatomic,weak) WKWebView *web;
@property(nonatomic,weak) UIViewController *presenter;
@property(nonatomic,copy) DVMOrigin *(^origin)(void);
@property(nonatomic,copy) void(^leave)(void(^)(void));
@property(nonatomic,copy) NSString *nonce;
@property(nonatomic,strong) NSMutableDictionary<NSString *,DVMNativeTransfer *> *transfers;
@end
@implementation DVMNativeBridge
- (instancetype)initWithWebView:(WKWebView *)web presenter:(UIViewController *)presenter origin:(DVMOrigin *(^)(void))origin leave:(void(^)(void(^)(void)))leave {
    if((self=[super init])){_web=web;_presenter=presenter;_origin=[origin copy];_leave=[leave copy];_transfers=[NSMutableDictionary new];[NSNotificationCenter.defaultCenter addObserver:self selector:@selector(notificationClicked:) name:@"DVMNotificationClicked" object:nil];}return self;
}
- (void)dealloc{[NSNotificationCenter.defaultCenter removeObserver:self];}
- (void)reset{
    self.nonce=nil;for(DVMNativeTransfer *transfer in self.transfers.allValues){[transfer.handle closeAndReturnError:nil];[NSFileManager.defaultManager removeItemAtURL:transfer.file.URLByDeletingLastPathComponent error:nil];}[self.transfers removeAllObjects];
}
- (BOOL)current:(NSString *)nonce {return [nonce isKindOfClass:NSString.class] && nonce.length && [nonce isEqual:self.nonce] && [self.origin() owns:self.web.URL];}
- (void)permission:(void(^)(NSString *))completion {
    [UNUserNotificationCenter.currentNotificationCenter getNotificationSettingsWithCompletionHandler:^(UNNotificationSettings *settings){
        NSString *value=settings.authorizationStatus==UNAuthorizationStatusNotDetermined?@"default":settings.authorizationStatus==UNAuthorizationStatusDenied?@"denied":@"granted";dispatch_async(dispatch_get_main_queue(),^{completion(value);});
    }];
}
- (void)send:(NSDictionary *)packet {
    if(![self current:packet[@"nonce"]])return;
    NSData *data=[NSJSONSerialization dataWithJSONObject:packet options:0 error:nil];if(!data)return;
    NSString *json=[[NSString alloc] initWithData:data encoding:NSUTF8StringEncoding];[self.web evaluateJavaScript:[NSString stringWithFormat:@"window.DVMNativeReceive?.(%@)",json] completionHandler:nil];
}
- (void)reply:(NSString *)identifier nonce:(NSString *)nonce result:(id)result error:(NSString *)error {
    if(![self current:nonce])return;NSMutableDictionary *packet=[@{@"nonce":nonce,@"id":identifier} mutableCopy];if(error)packet[@"error"]=error;else packet[@"result"]=result?:NSNull.null;[self send:packet];
}
- (void)attach {
    [self reset];if(![self.origin() owns:self.web.URL])return;self.nonce=NSUUID.UUID.UUIDString;NSString *nonce=self.nonce;
    [self permission:^(NSString *permission){
        if(![self current:nonce])return;
        NSURL *file=[NSBundle.mainBundle URLForResource:@"native-client" withExtension:@"js"];NSString *script=[NSString stringWithContentsOfURL:file encoding:NSUTF8StringEncoding error:nil];if(!script)return;
        NSDictionary *config=@{@"platform":@"ios",@"origin":self.origin().URL.absoluteString,@"nonce":nonce,@"permission":permission,@"payments":@(self.paymentAvailable&&self.paymentAvailable())};NSString *json=[[NSString alloc] initWithData:[NSJSONSerialization dataWithJSONObject:config options:0 error:nil] encoding:NSUTF8StringEncoding];
        [self.web evaluateJavaScript:[NSString stringWithFormat:@"(function(){const DVM_NATIVE_CONFIGURATION=%@;%@})();",json,script] completionHandler:nil];
        NSDictionary *pending=[NSUserDefaults.standardUserDefaults volatileDomainForName:@"DVMNotificationClick"];
        if(pending.count)[self notificationClicked:[NSNotification notificationWithName:@"DVMNotificationClicked" object:nil userInfo:pending]];
    }];
}
- (void)refreshPermission {
    NSString *nonce=self.nonce;[self permission:^(NSString *permission){if([self current:nonce])[self send:@{@"nonce":nonce,@"event":@"permission",@"permission":permission}];}];
}
- (BOOL)trusted:(WKScriptMessage *)message {
    WKSecurityOrigin *frame=message.frameInfo.securityOrigin;NSURLComponents *URL=[NSURLComponents new];URL.scheme=frame.protocol;URL.host=frame.host;if(frame.port)URL.port=@(frame.port);
    return message.frameInfo.mainFrame && [self.origin() owns:URL.URL] && [self.origin() owns:self.web.URL] && [message.body isKindOfClass:NSDictionary.class] && [self current:message.body[@"nonce"]];
}
- (void)userContentController:(WKUserContentController *)controller didReceiveScriptMessage:(WKScriptMessage *)message {
    if(![self trusted:message])return;
    NSDictionary *packet=message.body;NSString *identifier=packet[@"id"],*nonce=self.nonce,*operation=packet[@"operation"];
    if(![identifier isKindOfClass:NSString.class] || identifier.length>12 || !identifier.length || [identifier rangeOfCharacterFromSet:NSCharacterSet.decimalDigitCharacterSet.invertedSet].location!=NSNotFound || ![operation isKindOfClass:NSString.class])return;
    void(^reply)(id,NSString *)=^(id result,NSString *error){[self reply:identifier nonce:nonce result:result error:error];};
    if([operation isEqual:@"payment"]){reply(@(self.payment&&self.payment(packet)),nil);return;}
    if([operation isEqual:@"print"]){UIPrintInteractionController *print=UIPrintInteractionController.sharedPrintController;print.printFormatter=self.web.viewPrintFormatter;[print presentAnimated:YES completionHandler:nil];reply(@YES,nil);return;}
    if([operation isEqual:@"notification-permission"]){
        [UNUserNotificationCenter.currentNotificationCenter requestAuthorizationWithOptions:UNAuthorizationOptionAlert|UNAuthorizationOptionSound|UNAuthorizationOptionBadge completionHandler:^(BOOL granted,NSError *error){[self permission:^(NSString *permission){reply(permission,nil);}];}];return;
    }
    if([operation isEqual:@"notification-close"]){NSString *key=packet[@"notification"];if([key isKindOfClass:NSString.class])[UNUserNotificationCenter.currentNotificationCenter removeDeliveredNotificationsWithIdentifiers:@[key]];reply(@YES,nil);return;}
    if([operation isEqual:@"notification"]){
        NSString *target=packet[@"target"],*title=packet[@"title"],*body=packet[@"body"],*key=packet[@"notification"];
        if(![target isKindOfClass:NSString.class]||![title isKindOfClass:NSString.class]||![body isKindOfClass:NSString.class]||![key isKindOfClass:NSString.class]||key.length>80||title.length>200||body.length>1000||![self.origin() owns:[NSURL URLWithString:target]]){reply(nil,NSLocalizedString(@"native_notification_unconfirmed",nil));return;}
        UNMutableNotificationContent *content=[UNMutableNotificationContent new];content.title=title;content.body=body;content.sound=UNNotificationSound.defaultSound;content.userInfo=@{@"origin":self.origin().URL.absoluteString,@"target":target,@"nonce":nonce,@"notification":key};
        [UNUserNotificationCenter.currentNotificationCenter addNotificationRequest:[UNNotificationRequest requestWithIdentifier:key content:content trigger:nil] withCompletionHandler:^(NSError *error){dispatch_async(dispatch_get_main_queue(),^{reply(error?nil:@YES,error?NSLocalizedString(@"native_notification_unconfirmed",nil):nil);});}];return;
    }
    if([operation isEqual:@"file-start"]){
        NSString *name=packet[@"name"],*mime=packet[@"type"];NSNumber *size=packet[@"size"];
        if(self.transfers.count>=2||![name isKindOfClass:NSString.class]||![mime isKindOfClass:NSString.class]||![size isKindOfClass:NSNumber.class]||CFGetTypeID((__bridge CFTypeRef)size)==CFBooleanGetTypeID()||!isfinite(size.doubleValue)||size.doubleValue<0||size.doubleValue>1024.0*1024*1024||size.doubleValue!=(double)size.unsignedLongLongValue){reply(nil,NSLocalizedString(@"native_file_unconfirmed",nil));return;}
        NSString *leaf=name.lastPathComponent;if(![leaf isEqual:name]||[name containsString:@"\\"]||!leaf.length||leaf.length>200||[@[@".",@".."] containsObject:leaf]||[leaf rangeOfCharacterFromSet:NSCharacterSet.controlCharacterSet].location!=NSNotFound){reply(nil,NSLocalizedString(@"native_check_the_filename",nil));return;}
        NSString *key=NSUUID.UUID.UUIDString;NSURL *folder=[[[NSFileManager.defaultManager URLsForDirectory:NSCachesDirectory inDomains:NSUserDomainMask].firstObject URLByAppendingPathComponent:@"shared" isDirectory:YES] URLByAppendingPathComponent:key isDirectory:YES];NSError *error;
        [NSFileManager.defaultManager createDirectoryAtURL:folder withIntermediateDirectories:YES attributes:nil error:&error];NSURL *file=[folder URLByAppendingPathComponent:leaf];
        if(error||![NSFileManager.defaultManager createFileAtPath:file.path contents:nil attributes:nil]){reply(nil,NSLocalizedString(@"native_the_file_could_not_be_created",nil));return;}
        DVMNativeTransfer *transfer=[DVMNativeTransfer new];transfer.file=file;transfer.mime=mime;transfer.expected=size.unsignedIntegerValue;transfer.handle=[NSFileHandle fileHandleForWritingToURL:file error:&error];if(!transfer.handle){reply(nil,NSLocalizedString(@"native_the_file_could_not_be_opened",nil));return;}self.transfers[key]=transfer;reply(key,nil);return;
    }
    NSString *key=packet[@"job"];DVMNativeTransfer *transfer=[key isKindOfClass:NSString.class]?self.transfers[key]:nil;
    if([operation isEqual:@"file-chunk"]){
        NSString *encoded=packet[@"data"];NSNumber *sequence=packet[@"sequence"];
        if(!transfer||![encoded isKindOfClass:NSString.class]||encoded.length>90000||![sequence isKindOfClass:NSNumber.class]||sequence.doubleValue!=(double)transfer.sequence){reply(nil,NSLocalizedString(@"native_file_chunk_sequence_unconfirmed",nil));return;}
        NSData *chunk=[[NSData alloc] initWithBase64EncodedString:encoded options:0];if(!chunk.length||chunk.length>65536||transfer.received+chunk.length>transfer.expected){reply(nil,NSLocalizedString(@"native_file_size_unconfirmed",nil));return;}
        NSError *error;if(![transfer.handle writeData:chunk error:&error]){reply(nil,NSLocalizedString(@"native_the_file_could_not_be_transferred",nil));return;}transfer.received+=chunk.length;transfer.sequence++;reply(@YES,nil);return;
    }
    if([operation isEqual:@"file-cancel"]){if(transfer){[transfer.handle closeAndReturnError:nil];[NSFileManager.defaultManager removeItemAtURL:transfer.file.URLByDeletingLastPathComponent error:nil];[self.transfers removeObjectForKey:key];}reply(@YES,nil);return;}
    if([operation isEqual:@"file-finish"]){
        if(!transfer||transfer.received!=transfer.expected){reply(nil,NSLocalizedString(@"native_file_incomplete",nil));return;}NSError *error;if(![transfer.handle closeAndReturnError:&error]){reply(nil,NSLocalizedString(@"native_file_unconfirmed",nil));return;}[self.transfers removeObjectForKey:key];reply(@YES,nil);
        UIActivityViewController *share=[[UIActivityViewController alloc] initWithActivityItems:@[transfer.file] applicationActivities:nil];share.popoverPresentationController.barButtonItem=self.presenter.navigationItem.rightBarButtonItems.firstObject;[self.presenter presentViewController:share animated:YES completion:nil];return;
    }
    reply(nil,NSLocalizedString(@"native_this_app_feature_is_unavailable",nil));
}
- (void)notificationClicked:(NSNotification *)event {
    NSDictionary *info=event.userInfo;if(![info[@"origin"] isKindOfClass:NSString.class]||![info[@"origin"] isEqual:self.origin().URL.absoluteString]||![info[@"target"] isKindOfClass:NSString.class])return;NSURL *target=[NSURL URLWithString:info[@"target"]];if(![self.origin() owns:target])return;
    [NSUserDefaults.standardUserDefaults removeVolatileDomainForName:@"DVMNotificationClick"];
    self.leave(^{if([self current:info[@"nonce"]])[self send:@{@"nonce":self.nonce,@"event":@"notification-click",@"notification":info[@"notification"]?:@""}];else[self.web loadRequest:[NSURLRequest requestWithURL:target]];});
}
@end
