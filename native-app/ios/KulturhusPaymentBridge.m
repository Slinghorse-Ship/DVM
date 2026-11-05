#import "KulturhusPaymentBridge.h"
#import <SumUpSDK/SumUpSDK.h>
#import <CommonCrypto/CommonDigest.h>

@interface KulturhusPaymentBridge () <NSURLSessionTaskDelegate>
@property(nonatomic,strong) NSURL *origin;
@property(nonatomic,weak) WKWebView *webView;
@property(nonatomic,weak) UIViewController *presenter;
@property(nonatomic,assign,readwrite) BOOL busy;
@end

@implementation KulturhusPaymentBridge
- (instancetype)initWithOrigin:(NSURL *)origin webView:(WKWebView *)webView presenter:(UIViewController *)presenter {
    NSParameterAssert([origin.scheme isEqualToString:@"https"] && origin.host.length && !origin.user && !origin.password && (!origin.port || (origin.port.integerValue>0 && origin.port.integerValue<=65535)));
    if ((self = [super init])) { _origin = origin; _webView = webView; _presenter = presenter; }
    return self;
}
- (void)userContentController:(WKUserContentController *)controller didReceiveScriptMessage:(WKScriptMessage *)message {
    WKSecurityOrigin *security = message.frameInfo.securityOrigin;
    if (self.busy || !message.frameInfo.mainFrame || ![security.protocol isEqualToString:@"https"] || ![security.host isEqualToString:self.origin.host] || (security.port != 0 && security.port != (self.origin.port ? self.origin.port.integerValue : 443)) || ![message.body isKindOfClass:NSDictionary.class]) return;
    [self begin:message.body];
}
- (BOOL)current {
    NSURL *URL=self.webView.URL;return [URL.scheme isEqual:@"https"] && [URL.host.lowercaseString isEqual:self.origin.host.lowercaseString] && (URL.port?URL.port.integerValue:443)==(self.origin.port?self.origin.port.integerValue:443);
}
- (void)begin:(NSDictionary *)packet {
    if(self.busy||![self current]||![packet isKindOfClass:NSDictionary.class])return;
    NSString *attempt = packet[@"attempt"], *csrf = packet[@"csrf"];
    NSRegularExpression *identifier = [NSRegularExpression regularExpressionWithPattern:@"^[a-f0-9]{32}$" options:0 error:nil];
    NSRegularExpression *token = [NSRegularExpression regularExpressionWithPattern:@"^[a-f0-9]{64}$" options:0 error:nil];
    if (![attempt isKindOfClass:NSString.class] || ![csrf isKindOfClass:NSString.class] || [identifier numberOfMatchesInString:attempt options:0 range:NSMakeRange(0, attempt.length)] != 1 || [token numberOfMatchesInString:csrf options:0 range:NSMakeRange(0, csrf.length)] != 1) return;
    if (!SMPSumUpSDK.isLoggedIn || SMPSumUpSDK.checkoutInProgress || ![SMPSumUpSDK.currentMerchant.currencyCode isEqualToString:@"EUR"] || !self.presenter) { [self show:NSLocalizedString(@"native_sign_in_to_the_correct_sumup_account_first",nil)]; return; }
    self.busy = YES;
    // Read-only preflight first. Missing Tap to Pay availability never starts the server attempt.
    [self instructions:attempt csrf:csrf operation:@"native-info" completion:^(NSDictionary *instruction) {
        void (^begin)(void) = ^{
            [self instructions:attempt csrf:csrf operation:@"native-start" completion:^(NSDictionary *started) {
                for (NSString *field in @[@"attempt",@"reference",@"amount_cents",@"currency",@"merchant_code",@"method"]) {
                    if (![started[field] isEqual:instruction[field]]) { self.busy = NO; [self show:NSLocalizedString(@"native_payment_instruction_changed_check_the_attempt_on_the_server",nil)]; return; }
                }
                [self checkout:started];
            }];
        };
        if ([instruction[@"method"] isEqual:@"sumup-tap"]) {
            [SMPSumUpSDK checkTapToPayAvailability:^(BOOL available, BOOL activated, NSError *error) {
                dispatch_async(dispatch_get_main_queue(), ^{
                    if (!available || !activated || error) { self.busy = NO; [self show:NSLocalizedString(@"native_tap_to_pay_is_unavailable_or_not_enabled_no_payment_was_star",nil)]; return; }
                    begin();
                });
            }];
        } else begin();
    }];
}
- (void)instructions:(NSString *)attempt csrf:(NSString *)csrf operation:(NSString *)operation completion:(void (^)(NSDictionary *))completion {
    [self.webView.configuration.websiteDataStore.httpCookieStore getAllCookies:^(NSArray<NSHTTPCookie *> *cookies) {
        if(![self current]){self.busy=NO;return;}
        NSURL *endpoint = [[NSURL URLWithString:@"/vereinsverwaltung/?view=pos" relativeToURL:self.origin] absoluteURL];
        NSMutableURLRequest *request = [NSMutableURLRequest requestWithURL:endpoint]; request.HTTPMethod = @"POST"; request.timeoutInterval = 20;
        NSMutableArray *selected = [NSMutableArray array];
        for (NSHTTPCookie *cookie in cookies) if (cookie.secure && ([cookie.domain isEqualToString:self.origin.host] || [cookie.domain isEqualToString:[@"." stringByAppendingString:self.origin.host]])) [selected addObject:cookie];
        [request setAllHTTPHeaderFields:[NSHTTPCookie requestHeaderFieldsWithCookies:selected]];
        [request setValue:self.origin.absoluteString forHTTPHeaderField:@"Origin"];
        [request setValue:@"application/x-www-form-urlencoded" forHTTPHeaderField:@"Content-Type"];
        request.HTTPBody = [[NSString stringWithFormat:@"operation=%@&payment=%@&csrf=%@",operation,attempt,csrf] dataUsingEncoding:NSUTF8StringEncoding];
        NSURLSessionConfiguration *configuration = NSURLSessionConfiguration.ephemeralSessionConfiguration;
        configuration.HTTPShouldSetCookies = NO;
        // Never forward the manually selected authenticated cookie through a redirect.
        NSURLSession *session = [NSURLSession sessionWithConfiguration:configuration delegate:self delegateQueue:nil];
        [[session dataTaskWithRequest:request completionHandler:^(NSData *data, NSURLResponse *response, NSError *error) {
            NSHTTPURLResponse *http = (NSHTTPURLResponse *)response;
            NSDictionary *body = data.length <= 65536 ? [NSJSONSerialization JSONObjectWithData:data options:0 error:nil] : nil;
            NSDictionary *instruction = [body isKindOfClass:NSDictionary.class] ? body[@"native"] : nil;
            dispatch_async(dispatch_get_main_queue(), ^{
                if (![instruction isKindOfClass:NSDictionary.class]) { self.busy = NO; [self show:NSLocalizedString(@"native_payment_instruction_missing_check_the_attempt_on_the_server",nil)]; return; }
                NSNumber *cents = instruction[@"amount_cents"];
                if (![self current] || error || http.statusCode != 200 || ![response.URL isEqual:endpoint] || ![instruction isKindOfClass:NSDictionary.class] || ![instruction[@"attempt"] isEqual:attempt] || ![instruction[@"reference"] isEqual:[@"KH-" stringByAppendingString:attempt]] || ![cents isKindOfClass:NSNumber.class] || cents.longLongValue<=0 || cents.longLongValue>100000000 || cents.doubleValue != (double)cents.longLongValue || ![instruction[@"currency"] isEqual:@"EUR"] || ![instruction[@"merchant_code"] isEqual:SMPSumUpSDK.currentMerchant.merchantCode] || ![@[@"sumup-tap",@"sumup-reader"] containsObject:instruction[@"method"]] || !SMPSumUpSDK.isLoggedIn || SMPSumUpSDK.checkoutInProgress || !self.presenter) { self.busy = NO; [self show:NSLocalizedString(@"native_attempt_not_started_check_the_merchant_and_saved_result_in_t",nil)]; return; }
                completion(instruction);
            });
            [session finishTasksAndInvalidate];
        }] resume];
    }];
}

- (void)URLSession:(NSURLSession *)session task:(NSURLSessionTask *)task willPerformHTTPRedirection:(NSHTTPURLResponse *)response newRequest:(NSURLRequest *)request completionHandler:(void (^)(NSURLRequest *))completionHandler {
    completionHandler(nil);
}
- (void)checkout:(NSDictionary *)instruction {
    NSNumber *cents = instruction[@"amount_cents"]; NSString *reference = instruction[@"reference"], *method = instruction[@"method"], *merchant = instruction[@"merchant_code"];
    if (![self current] || ![cents isKindOfClass:NSNumber.class] || cents.longLongValue <= 0 || cents.longLongValue > 100000000 || ![instruction[@"currency"] isEqual:@"EUR"] || ![reference isKindOfClass:NSString.class] || ![reference isEqual:[@"KH-" stringByAppendingString:instruction[@"attempt"]]] || ![merchant isKindOfClass:NSString.class] || ![SMPSumUpSDK.currentMerchant.merchantCode isEqualToString:merchant] || ![SMPSumUpSDK.currentMerchant.currencyCode isEqualToString:@"EUR"] || !SMPSumUpSDK.isLoggedIn || SMPSumUpSDK.checkoutInProgress || !self.presenter) { self.busy = NO; [self show:NSLocalizedString(@"native_check_the_sumup_sign_in_merchant_and_currency_the_payment_at",nil)]; return; }
    if (![@[@"sumup-reader",@"sumup-tap"] containsObject:method]) { self.busy = NO; return; }
    NSString *key = [@"kulturhus-started-" stringByAppendingString:reference];
    NSURL *marker=[self markerFor:[NSString stringWithFormat:@"%@|%@|%@",self.origin.absoluteString,merchant,reference]];
    if (!marker || [NSFileManager.defaultManager fileExistsAtPath:marker.path] || [[NSUserDefaults standardUserDefaults] boolForKey:key]) { self.busy = NO; [self show:NSLocalizedString(@"native_check_this_attempt_independently_on_the_server_first",nil)]; return; }
    void (^start)(void) = ^{
        NSError *markerError;
        if(![[NSData dataWithBytes:"started" length:7] writeToURL:marker options:NSDataWritingAtomic|NSDataWritingFileProtectionComplete error:&markerError]){self.busy=NO;[self show:NSLocalizedString(@"native_payment_attempt_unconfirmed_review_the_saved_result_on_the_s",nil)];return;}
        NSDecimalNumber *amount = [[NSDecimalNumber decimalNumberWithMantissa:cents.unsignedLongLongValue exponent:0 isNegative:NO] decimalNumberByDividingBy:[NSDecimalNumber decimalNumberWithString:@"100"]];
        SMPCheckoutRequest *request = [SMPCheckoutRequest requestWithTotal:amount title:reference currencyCode:@"EUR" paymentMethod:[method isEqual:@"sumup-tap"] ? SMPPaymentMethodTapToPay : SMPPaymentMethodCardReader];
        request.foreignTransactionID = reference;
        [SMPSumUpSDK checkoutWithRequest:request fromViewController:self.presenter completion:^(SMPCheckoutResult *result, NSError *error) {
            self.busy = NO;
            // A successful SDK result is deliberately never marked as a paid sale here.
            [self show:NSLocalizedString(@"native_device_attempt_finished_check_the_payment_independently_in_t",nil)];
            [self.webView loadRequest:[NSURLRequest requestWithURL:[[NSURL URLWithString:@"/vereinsverwaltung/?view=pos" relativeToURL:self.origin] absoluteURL]]];
        }];
    };
    if ([method isEqual:@"sumup-tap"]) [SMPSumUpSDK checkTapToPayAvailability:^(BOOL available, BOOL activated, NSError *error) {
        if (!available || !activated || error) { self.busy = NO; [self show:NSLocalizedString(@"native_enable_tap_to_pay_for_this_account_and_device_in_sumup_first",nil)]; return; }
        start();
    }]; else start();
}
- (NSURL *)markerFor:(NSString *)reference {
    NSData *bytes=[reference dataUsingEncoding:NSUTF8StringEncoding];unsigned char digest[CC_SHA256_DIGEST_LENGTH];CC_SHA256(bytes.bytes,(CC_LONG)bytes.length,digest);NSMutableString *name=[NSMutableString new];for(NSUInteger i=0;i<CC_SHA256_DIGEST_LENGTH;i++)[name appendFormat:@"%02x",digest[i]];
    NSURL *directory=[[NSFileManager.defaultManager URLsForDirectory:NSApplicationSupportDirectory inDomains:NSUserDomainMask].firstObject URLByAppendingPathComponent:@"dvm-pos-starts" isDirectory:YES];NSError *error;
    if(![NSFileManager.defaultManager createDirectoryAtURL:directory withIntermediateDirectories:YES attributes:@{NSFileProtectionKey:NSFileProtectionComplete} error:&error])return nil;
    if(![directory setResourceValue:@YES forKey:NSURLIsExcludedFromBackupKey error:&error])return nil;
    return [directory URLByAppendingPathComponent:name];
}
- (void)settings {
    if(self.busy||SMPSumUpSDK.checkoutInProgress){[self show:NSLocalizedString(@"native_a_payment_attempt_is_in_progress_check_its_result_first",nil)];return;}
    if(!SMPSumUpSDK.isLoggedIn){[SMPSumUpSDK presentLoginFromViewController:self.presenter animated:YES completionBlock:^(BOOL success,NSError *error){}];return;}
    UIAlertController *menu=[UIAlertController alertControllerWithTitle:NSLocalizedString(@"native_card_terminal",nil) message:nil preferredStyle:UIAlertControllerStyleActionSheet];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_card_reader",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){[SMPSumUpSDK presentCardReaderSettingsFromViewController:self.presenter animated:YES completion:^(BOOL success,NSError *error){}];}]];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_enable_tap_to_pay",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){[SMPSumUpSDK presentTapToPayActivationFromViewController:self.presenter animated:YES completionBlock:^(BOOL success,NSError *error){}];}]];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_sign_out",nil) style:UIAlertActionStyleDestructive handler:^(UIAlertAction *action){[SMPSumUpSDK logoutWithCompletionBlock:^(BOOL success,NSError *error){}];}]];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_cancel",nil) style:UIAlertActionStyleCancel handler:nil]];
    menu.popoverPresentationController.barButtonItem=self.presenter.navigationItem.rightBarButtonItems.firstObject;[self.presenter presentViewController:menu animated:YES completion:nil];
}
- (void)show:(NSString *)text {
    UIAlertController *alert = [UIAlertController alertControllerWithTitle:NSLocalizedString(@"native_checkout",nil) message:text preferredStyle:UIAlertControllerStyleAlert];
    [alert addAction:[UIAlertAction actionWithTitle:@"OK" style:UIAlertActionStyleDefault handler:nil]];
    [self.presenter presentViewController:alert animated:YES completion:nil];
}
@end
