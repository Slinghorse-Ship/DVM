#import <Foundation/Foundation.h>
#import <WebKit/WebKit.h>
#import <UIKit/UIKit.h>

/// Install only on a WKWebView whose navigation is restricted to the configured HTTPS origin.
@interface KulturhusPaymentBridge : NSObject <WKScriptMessageHandler>
@property(nonatomic,readonly) BOOL busy;
- (void)begin:(NSDictionary *)packet;
- (void)settings;
- (instancetype)initWithOrigin:(NSURL *)origin webView:(WKWebView *)webView presenter:(UIViewController *)presenter;
@end
