#import <UIKit/UIKit.h>
#import <UserNotifications/UserNotifications.h>
@interface DVMAppDelegate : UIResponder <UIApplicationDelegate, UNUserNotificationCenterDelegate>
@property(nonatomic,strong) UIWindow *window;
@property(nonatomic,assign) BOOL sumupReady;
@end
