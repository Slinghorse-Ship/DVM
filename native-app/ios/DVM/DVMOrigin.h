#import <Foundation/Foundation.h>
NS_ASSUME_NONNULL_BEGIN
@interface DVMOrigin : NSObject
@property(nonatomic,strong,readonly) NSURL *URL;
+ (nullable instancetype)originFromString:(NSString *)input allowLocalHTTP:(BOOL)debug;
- (BOOL)owns:(nullable NSURL *)URL;
- (NSURL *)startURL;
+ (BOOL)externalURL:(NSURL *)URL;
@end
NS_ASSUME_NONNULL_END
