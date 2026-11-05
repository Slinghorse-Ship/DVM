#import "DVMSceneDelegate.h"
#import "DVMViewController.h"
@implementation DVMSceneDelegate
- (void)scene:(UIScene *)scene willConnectToSession:(UISceneSession *)session options:(UISceneConnectionOptions *)options {
    if (![scene isKindOfClass:UIWindowScene.class]) return;
    self.window = [[UIWindow alloc] initWithWindowScene:(UIWindowScene *)scene];
    self.window.rootViewController = [[UINavigationController alloc] initWithRootViewController:[DVMViewController new]];
    [self.window makeKeyAndVisible];
}
@end
