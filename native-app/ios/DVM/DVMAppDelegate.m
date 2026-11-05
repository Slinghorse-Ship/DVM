#import "DVMAppDelegate.h"
#import <SumUpSDK/SumUpSDK.h>
@implementation DVMAppDelegate
- (BOOL)application:(UIApplication *)application didFinishLaunchingWithOptions:(NSDictionary *)options {
    NSString *affiliate=[NSBundle.mainBundle objectForInfoDictionaryKey:@"DVMSumUpAffiliateKey"];
    if([affiliate isKindOfClass:NSString.class]&&[affiliate hasPrefix:@"sup_afk_"])self.sumupReady=[SMPSumUpSDK setupWithAffiliateKey:affiliate];
    UNUserNotificationCenter.currentNotificationCenter.delegate=self;return YES;
}
- (void)userNotificationCenter:(UNUserNotificationCenter *)center willPresentNotification:(UNNotification *)notification withCompletionHandler:(void (^)(UNNotificationPresentationOptions))completion {
    completion(UNNotificationPresentationOptionBanner|UNNotificationPresentationOptionSound);
}
- (void)userNotificationCenter:(UNUserNotificationCenter *)center didReceiveNotificationResponse:(UNNotificationResponse *)response withCompletionHandler:(void (^)(void))completion {
    if([response.actionIdentifier isEqual:UNNotificationDefaultActionIdentifier]) {
        NSDictionary *info=response.notification.request.content.userInfo;
        // Memory only: preserve a cold-launch click until the selected server is ready.
        [NSUserDefaults.standardUserDefaults setVolatileDomain:info forName:@"DVMNotificationClick"];
        [NSNotificationCenter.defaultCenter postNotificationName:@"DVMNotificationClicked" object:nil userInfo:info];
    }
    completion();
}
@end
