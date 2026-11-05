#import "DVMViewController.h"
#import "DVMOrigin.h"
#import "DVMNativeBridge.h"
#import "KulturhusPaymentBridge.h"
#import "DVMAppDelegate.h"
#import <WebKit/WebKit.h>

@interface DVMViewController () <WKNavigationDelegate, WKUIDelegate, WKDownloadDelegate>
@property(nonatomic,strong) WKWebView *web;
@property(nonatomic,strong) DVMNativeBridge *bridge;
@property(nonatomic,strong) KulturhusPaymentBridge *paymentBridge;
@property(nonatomic,strong) NSString *paymentOrigin;
@property(nonatomic,assign) BOOL sumupReady;
@property(nonatomic,strong) DVMOrigin *origin;
@property(nonatomic,strong) UILabel *notice;
@property(nonatomic,strong) UIVisualEffectView *privacy;
@property(nonatomic,weak) UIAlertController *serverDialog;
@property(nonatomic,weak) UIAlertAction *serverOpenAction;
@property(nonatomic,strong) NSMapTable<WKDownload *,NSDictionary *> *downloads;
@end

@implementation DVMViewController
- (void)viewDidLoad {
    [super viewDidLoad];self.title = @"DVM";self.view.backgroundColor = UIColor.systemBackgroundColor;
    self.downloads = [NSMapTable strongToStrongObjectsMapTable];
    WKWebViewConfiguration *configuration = [WKWebViewConfiguration new];configuration.websiteDataStore = WKWebsiteDataStore.defaultDataStore;
    configuration.applicationNameForUserAgent = @"DVMApp/1.0 iOS";
    self.web = [[WKWebView alloc] initWithFrame:CGRectZero configuration:configuration];self.web.navigationDelegate = self;self.web.UIDelegate = self;
    __weak DVMViewController *owner=self;
    self.sumupReady=((DVMAppDelegate *)UIApplication.sharedApplication.delegate).sumupReady;
    self.bridge=[[DVMNativeBridge alloc] initWithWebView:self.web presenter:self origin:^DVMOrigin *{return owner.origin;} leave:^(void(^action)(void)){[owner leave:action];}];
    self.bridge.paymentAvailable=^BOOL{return owner.sumupReady&&[owner.origin.URL.scheme isEqual:@"https"];};
    self.bridge.payment=^BOOL(NSDictionary *packet){if(!owner.sumupReady||!owner.paymentBridge)return NO;[owner.paymentBridge begin:packet];return YES;};
    [self.web.configuration.userContentController addScriptMessageHandler:self.bridge name:@"dvmApp"];
    self.web.allowsBackForwardNavigationGestures = NO;self.web.translatesAutoresizingMaskIntoConstraints = NO;[self.view addSubview:self.web];
    [NSLayoutConstraint activateConstraints:@[
        [self.web.topAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.topAnchor],
        [self.web.leadingAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.leadingAnchor],
        [self.web.trailingAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.trailingAnchor],
        [self.web.bottomAnchor constraintEqualToAnchor:self.view.keyboardLayoutGuide.topAnchor]
    ]];
    self.navigationItem.leftBarButtonItem = [[UIBarButtonItem alloc] initWithImage:[UIImage systemImageNamed:@"chevron.backward"] style:UIBarButtonItemStylePlain target:self action:@selector(back)];self.navigationItem.leftBarButtonItem.accessibilityLabel = NSLocalizedString(@"native_back",nil);
    self.navigationItem.rightBarButtonItems = @[
        [[UIBarButtonItem alloc] initWithImage:[UIImage systemImageNamed:@"ellipsis.circle"] style:UIBarButtonItemStylePlain target:self action:@selector(menu)],
        [[UIBarButtonItem alloc] initWithBarButtonSystemItem:UIBarButtonSystemItemRefresh target:self action:@selector(refresh)]
    ];self.navigationItem.rightBarButtonItems.firstObject.accessibilityLabel = NSLocalizedString(@"native_app_menu",nil);
    self.notice = [UILabel new];self.notice.translatesAutoresizingMaskIntoConstraints = NO;self.notice.numberOfLines = 0;self.notice.font = [UIFont preferredFontForTextStyle:UIFontTextStyleCallout];self.notice.adjustsFontForContentSizeCategory = YES;self.notice.backgroundColor = UIColor.secondarySystemBackgroundColor;self.notice.hidden = YES;[self.view addSubview:self.notice];
    [NSLayoutConstraint activateConstraints:@[[self.notice.leadingAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.leadingAnchor constant:12],[self.notice.trailingAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.trailingAnchor constant:-12],[self.notice.topAnchor constraintEqualToAnchor:self.view.safeAreaLayoutGuide.topAnchor constant:8]]];
    self.privacy = [[UIVisualEffectView alloc] initWithEffect:[UIBlurEffect effectWithStyle:UIBlurEffectStyleRegular]];self.privacy.frame = self.view.bounds;self.privacy.autoresizingMask = UIViewAutoresizingFlexibleWidth|UIViewAutoresizingFlexibleHeight;self.privacy.hidden = YES;[self.view addSubview:self.privacy];
    [NSNotificationCenter.defaultCenter addObserver:self selector:@selector(hidePrivateContent) name:UIApplicationWillResignActiveNotification object:nil];
    [NSNotificationCenter.defaultCenter addObserver:self selector:@selector(resume) name:UIApplicationDidBecomeActiveNotification object:nil];
    NSString *saved = [NSUserDefaults.standardUserDefaults stringForKey:@"dvm-origin"];
    self.origin = [DVMOrigin originFromString:saved ?: @"" allowLocalHTTP:self.debugLocal];
    if (self.origin) [self load:self.origin.startURL];
    else dispatch_async(dispatch_get_main_queue(), ^{ [self chooseServer]; });
}
- (BOOL)debugLocal {
#if DEBUG
    return YES;
#else
    return NO;
#endif
}
- (void)hidePrivateContent { self.privacy.hidden = NO; }
- (void)resume { self.privacy.hidden = YES;[self.bridge refreshPermission]; }
- (void)dealloc { [NSNotificationCenter.defaultCenter removeObserver:self]; }
- (void)show:(NSString *)message { self.notice.text = message;self.notice.hidden = NO;UIAccessibilityPostNotification(UIAccessibilityAnnouncementNotification,message); }
- (void)load:(NSURL *)URL { if ([self.origin owns:URL]) {self.notice.hidden = YES;[self.web loadRequest:[NSURLRequest requestWithURL:URL]];} }
- (NSURL *)safeURL { return [self.origin owns:self.web.URL] ? self.web.URL : self.origin.startURL; }
- (void)leave:(void (^)(void))action {
    if(self.paymentBridge.busy){[self show:NSLocalizedString(@"native_a_payment_attempt_is_in_progress_check_its_result_first",nil)];return;}
    if (![self.origin owns:self.web.URL]) {action();return;}
    [self.web evaluateJavaScript:@"Boolean(window.KulturhusNavigation?.dirty?.() || window.KulturhusPage?.guarded?.())" completionHandler:^(id result,NSError *error){
        if ([result respondsToSelector:@selector(boolValue)] && [result boolValue]) {
            UIAlertController *dialog = [UIAlertController alertControllerWithTitle:NSLocalizedString(@"native_discard_your_changes",nil) message:NSLocalizedString(@"native_unsaved_changes_will_be_lost",nil) preferredStyle:UIAlertControllerStyleAlert];
            [dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_cancel",nil) style:UIAlertActionStyleCancel handler:nil]];
            [dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_discard",nil) style:UIAlertActionStyleDestructive handler:^(UIAlertAction *item){action();}]];[self presentViewController:dialog animated:YES completion:nil];
        } else action();
    }];
}
- (void)refresh { [self leave:^{[self load:self.safeURL];}]; }
- (void)back { [self leave:^{if(self.web.canGoBack)[self.web goBack];}]; }
- (void)validateServerAddress:(UITextField *)field {
    BOOL valid = [DVMOrigin originFromString:field.text ?: @"" allowLocalHTTP:self.debugLocal] != nil;
    self.serverOpenAction.enabled = valid;
    self.serverDialog.message = valid || field.text.length == 0 ? nil : NSLocalizedString(@"native_enter_your_club_https_address_without_a_path_credentials_or_",nil);
}
- (void)chooseServer {
    UIAlertController *dialog = [UIAlertController alertControllerWithTitle:NSLocalizedString(@"native_your_club",nil) message:nil preferredStyle:UIAlertControllerStyleAlert];
    [dialog addTextFieldWithConfigurationHandler:^(UITextField *field){field.placeholder = @"https://verein.example";field.text = self.origin.URL.absoluteString;field.keyboardType = UIKeyboardTypeURL;field.autocorrectionType = UITextAutocorrectionTypeNo;field.autocapitalizationType = UITextAutocapitalizationTypeNone;field.accessibilityLabel = NSLocalizedString(@"native_club_address",nil);[field addTarget:self action:@selector(validateServerAddress:) forControlEvents:UIControlEventEditingChanged];}];
    [dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_cancel",nil) style:UIAlertActionStyleCancel handler:nil]];
    [dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_open",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *item){
        DVMOrigin *selected = [DVMOrigin originFromString:dialog.textFields.firstObject.text ?: @"" allowLocalHTTP:self.debugLocal];
        if (!selected) {[self show:NSLocalizedString(@"native_enter_your_club_https_address_without_a_path_credentials_or_",nil)];dispatch_async(dispatch_get_main_queue(),^{[self chooseServer];});return;}
        void (^open)(void) = ^{self.origin = selected;[NSUserDefaults.standardUserDefaults setObject:selected.URL.absoluteString forKey:@"dvm-origin"];[self load:selected.startURL];};
        if (self.origin && ![self.origin.URL.absoluteString isEqual:selected.URL.absoluteString]) [self.web.configuration.websiteDataStore removeDataOfTypes:WKWebsiteDataStore.allWebsiteDataTypes modifiedSince:[NSDate dateWithTimeIntervalSince1970:0] completionHandler:open];else open();
    }]];self.serverDialog = dialog;self.serverOpenAction = dialog.actions.lastObject;[self validateServerAddress:dialog.textFields.firstObject];[self presentViewController:dialog animated:YES completion:nil];
}
- (void)external:(NSURL *)URL { if ([DVMOrigin externalURL:URL]) [UIApplication.sharedApplication openURL:URL options:@{} completionHandler:nil]; }
- (void)menu {
    UIAlertController *menu = [UIAlertController alertControllerWithTitle:@"DVM" message:nil preferredStyle:UIAlertControllerStyleActionSheet];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_switch_club",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){[self leave:^{[self chooseServer];}];}]];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_open_in_browser",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){[self external:self.safeURL];}]];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_print",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){UIPrintInteractionController *print = UIPrintInteractionController.sharedPrintController;print.printFormatter = self.web.viewPrintFormatter;[print presentAnimated:YES completionHandler:nil];}]];
    if(self.sumupReady&&self.paymentBridge)[menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_card_terminal",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){[self.paymentBridge settings];}]];
    [menu addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_cancel",nil) style:UIAlertActionStyleCancel handler:nil]];menu.popoverPresentationController.barButtonItem = self.navigationItem.rightBarButtonItems.firstObject;[self presentViewController:menu animated:YES completion:nil];
}
- (void)webView:(WKWebView *)webView decidePolicyForNavigationAction:(WKNavigationAction *)action decisionHandler:(void (^)(WKNavigationActionPolicy))decision {
    NSURL *URL = action.request.URL;
    if ([self.origin owns:URL]) {
        if ([action.request.HTTPMethod isEqual:@"POST"] && action.navigationType == WKNavigationTypeBackForward) {decision(WKNavigationActionPolicyCancel);[self show:NSLocalizedString(@"native_the_action_will_not_be_sent_again_review_the_saved_result_in_43daed",nil)];[self load:self.origin.startURL];return;}
        decision(action.shouldPerformDownload ? WKNavigationActionPolicyDownload : WKNavigationActionPolicyAllow);return;
    }
    if(action.targetFrame&&!action.targetFrame.mainFrame&&[DVMOrigin externalURL:URL]&&[URL.scheme isEqual:@"https"]){decision(WKNavigationActionPolicyAllow);return;}
    if ((!action.targetFrame || action.targetFrame.mainFrame) && [action.request.HTTPMethod isEqual:@"GET"]) [self external:URL];
    decision(WKNavigationActionPolicyCancel);
}
- (void)webView:(WKWebView *)webView decidePolicyForNavigationResponse:(WKNavigationResponse *)response decisionHandler:(void (^)(WKNavigationResponsePolicy))decision {
    if (![self.origin owns:response.response.URL]) {decision(WKNavigationResponsePolicyCancel);return;}
    decision(response.canShowMIMEType ? WKNavigationResponsePolicyAllow : WKNavigationResponsePolicyDownload);
}
- (WKWebView *)webView:(WKWebView *)webView createWebViewWithConfiguration:(WKWebViewConfiguration *)configuration forNavigationAction:(WKNavigationAction *)action windowFeatures:(WKWindowFeatures *)features {
    if ([self.origin owns:action.request.URL]) [self leave:^{[self load:action.request.URL];}];else [self external:action.request.URL];return nil;
}
- (void)webView:(WKWebView *)webView didStartProvisionalNavigation:(WKNavigation *)navigation {[self.bridge reset];}
- (void)webView:(WKWebView *)webView didFinishNavigation:(WKNavigation *)navigation {
    if(self.sumupReady&&[self.origin.URL.scheme isEqual:@"https"]&&![self.paymentOrigin isEqual:self.origin.URL.absoluteString]){self.paymentBridge=[[KulturhusPaymentBridge alloc] initWithOrigin:self.origin.URL webView:self.web presenter:self];self.paymentOrigin=self.origin.URL.absoluteString;}
    [self.bridge attach];
}
- (void)webViewWebContentProcessDidTerminate:(WKWebView *)webView {[self.bridge reset];[self show:NSLocalizedString(@"native_the_view_was_closed_review_the_saved_result_before_sending_a",nil)];}
- (void)webView:(WKWebView *)webView didFailProvisionalNavigation:(WKNavigation *)navigation withError:(NSError *)error { if(error.code != NSURLErrorCancelled)[self show:NSLocalizedString(@"native_connection_interrupted_review_the_saved_result_before_sendin",nil)]; }
- (void)webView:(WKWebView *)webView didFailNavigation:(WKNavigation *)navigation withError:(NSError *)error { [self webView:webView didFailProvisionalNavigation:navigation withError:error]; }
- (void)webView:(WKWebView *)webView didReceiveAuthenticationChallenge:(NSURLAuthenticationChallenge *)challenge completionHandler:(void (^)(NSURLSessionAuthChallengeDisposition,NSURLCredential *))completion { completion(NSURLSessionAuthChallengePerformDefaultHandling,nil); }
- (BOOL)trustedFrame:(WKFrameInfo *)frame {WKSecurityOrigin *origin = frame.securityOrigin;NSURLComponents *URL = [NSURLComponents new];URL.scheme = origin.protocol;URL.host = origin.host;if(origin.port)URL.port = @(origin.port);return frame.mainFrame && [self.origin owns:URL.URL] && [self.origin owns:self.web.URL];}
- (void)webView:(WKWebView *)webView runJavaScriptAlertPanelWithMessage:(NSString *)message initiatedByFrame:(WKFrameInfo *)frame completionHandler:(void (^)(void))completion {
    if (![self trustedFrame:frame]){completion();return;}UIAlertController *dialog = [UIAlertController alertControllerWithTitle:@"DVM" message:message preferredStyle:UIAlertControllerStyleAlert];[dialog addAction:[UIAlertAction actionWithTitle:@"OK" style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){completion();}]];[self presentViewController:dialog animated:YES completion:nil];
}
- (void)webView:(WKWebView *)webView runJavaScriptConfirmPanelWithMessage:(NSString *)message initiatedByFrame:(WKFrameInfo *)frame completionHandler:(void (^)(BOOL))completion {
    if (![self trustedFrame:frame]){completion(NO);return;}UIAlertController *dialog = [UIAlertController alertControllerWithTitle:@"DVM" message:message preferredStyle:UIAlertControllerStyleAlert];[dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_cancel",nil) style:UIAlertActionStyleCancel handler:^(UIAlertAction *action){completion(NO);}]];[dialog addAction:[UIAlertAction actionWithTitle:@"Weiter" style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){completion(YES);}]];[self presentViewController:dialog animated:YES completion:nil];
}
- (void)webView:(WKWebView *)webView runJavaScriptTextInputPanelWithPrompt:(NSString *)prompt defaultText:(NSString *)text initiatedByFrame:(WKFrameInfo *)frame completionHandler:(void (^)(NSString *))completion {
    if (![self trustedFrame:frame]){completion(nil);return;}UIAlertController *dialog = [UIAlertController alertControllerWithTitle:@"DVM" message:prompt preferredStyle:UIAlertControllerStyleAlert];[dialog addTextFieldWithConfigurationHandler:^(UITextField *field){field.text = text;}];[dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_cancel",nil) style:UIAlertActionStyleCancel handler:^(UIAlertAction *action){completion(nil);}]];[dialog addAction:[UIAlertAction actionWithTitle:NSLocalizedString(@"native_apply",nil) style:UIAlertActionStyleDefault handler:^(UIAlertAction *action){completion(dialog.textFields.firstObject.text);}]];[self presentViewController:dialog animated:YES completion:nil];
}
- (void)webView:(WKWebView *)webView requestMediaCapturePermissionForOrigin:(WKSecurityOrigin *)origin initiatedByFrame:(WKFrameInfo *)frame type:(WKMediaCaptureType)type decisionHandler:(void (^)(WKPermissionDecision))decision { decision([self trustedFrame:frame] && type == WKMediaCaptureTypeCamera ? WKPermissionDecisionPrompt : WKPermissionDecisionDeny); }
- (void)webView:(WKWebView *)webView navigationAction:(WKNavigationAction *)action didBecomeDownload:(WKDownload *)download { download.delegate = self; }
- (void)webView:(WKWebView *)webView navigationResponse:(WKNavigationResponse *)response didBecomeDownload:(WKDownload *)download { download.delegate = self; }
- (void)download:(WKDownload *)download decideDestinationUsingResponse:(NSURLResponse *)response suggestedFilename:(NSString *)name completionHandler:(void (^)(NSURL *))completion {
    if (![self.origin owns:response.URL]){completion(nil);return;}
    NSString *leaf = name.lastPathComponent;if(!leaf.length || [@[@".",@".."] containsObject:leaf])leaf = NSLocalizedString(@"native_file",nil);
    NSURL *folder = [[[NSFileManager.defaultManager URLsForDirectory:NSCachesDirectory inDomains:NSUserDomainMask].firstObject URLByAppendingPathComponent:@"shared" isDirectory:YES] URLByAppendingPathComponent:NSUUID.UUID.UUIDString isDirectory:YES];
    NSError *error;[NSFileManager.defaultManager createDirectoryAtURL:folder withIntermediateDirectories:YES attributes:nil error:&error];if(error){completion(nil);return;}
    NSURL *file = [folder URLByAppendingPathComponent:leaf];[self.downloads setObject:@{@"file":file,@"origin":self.origin.URL.absoluteString} forKey:download];completion(file);
}
- (void)download:(WKDownload *)download willPerformHTTPRedirection:(NSHTTPURLResponse *)response newRequest:(NSURLRequest *)request decisionHandler:(void (^)(WKDownloadRedirectPolicy))decision { decision([self.origin owns:request.URL] ? WKDownloadRedirectPolicyAllow : WKDownloadRedirectPolicyCancel); }
- (void)downloadDidFinish:(WKDownload *)download {
    NSDictionary *record = [self.downloads objectForKey:download];[self.downloads removeObjectForKey:download];if(!record || ![record[@"origin"] isEqual:self.origin.URL.absoluteString])return;
    UIActivityViewController *share = [[UIActivityViewController alloc] initWithActivityItems:@[record[@"file"]] applicationActivities:nil];share.popoverPresentationController.barButtonItem = self.navigationItem.rightBarButtonItems.firstObject;[self presentViewController:share animated:YES completion:nil];
}
- (void)download:(WKDownload *)download didFailWithError:(NSError *)error resumeData:(NSData *)data { NSDictionary *record = [self.downloads objectForKey:download];if(record)[NSFileManager.defaultManager removeItemAtURL:record[@"file"] error:nil];[self.downloads removeObjectForKey:download];[self show:NSLocalizedString(@"native_download_unconfirmed_open_the_original_file_again_deliberate",nil)]; }
@end
