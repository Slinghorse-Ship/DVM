#import <Foundation/Foundation.h>
#import <WebKit/WebKit.h>
#import <UIKit/UIKit.h>
#import "DVMOrigin.h"
@interface DVMNativeBridge : NSObject <WKScriptMessageHandler>
- (instancetype)initWithWebView:(WKWebView *)web presenter:(UIViewController *)presenter origin:(DVMOrigin *(^)(void))origin leave:(void(^)(void(^)(void)))leave;
@property(nonatomic,copy) BOOL(^paymentAvailable)(void);
@property(nonatomic,copy) BOOL(^payment)(NSDictionary *);
- (void)attach;
- (void)reset;
- (void)refreshPermission;
@end
