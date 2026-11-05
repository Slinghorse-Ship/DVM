#import "DVMOrigin.h"
@interface DVMOrigin ()
@property(nonatomic,strong,readwrite) NSURL *URL;
@end
@implementation DVMOrigin
+ (instancetype)originFromString:(NSString *)input allowLocalHTTP:(BOOL)debug {
    NSString *raw = [input stringByTrimmingCharactersInSet:NSCharacterSet.whitespaceAndNewlineCharacterSet];
    NSURLComponents *parts = [NSURLComponents componentsWithString:raw];
    if (!parts || !parts.host.length || parts.user || parts.password || parts.query || parts.fragment || (parts.path.length && ![parts.path isEqual:@"/"])) return nil;
    NSRange separator = [raw rangeOfString:@"://"];
    if (separator.location == NSNotFound) return nil;
    NSString *authority = [[raw substringFromIndex:NSMaxRange(separator)] componentsSeparatedByString:@"/"].firstObject;
    if ([authority rangeOfCharacterFromSet:[NSCharacterSet characterSetWithCharactersInString:@"%@\\\r\n\t"]].location != NSNotFound) return nil;
    NSString *host = parts.URL.host.lowercaseString;
    if (!host.length || [host hasSuffix:@"."]) return nil;
    NSString *scheme = parts.scheme.lowercaseString;
    BOOL local = [@[@"localhost",@"127.0.0.1",@"[::1]",@"::1"] containsObject:host];
    if (![scheme isEqual:@"https"] && !(debug && local && [scheme isEqual:@"http"])) return nil;
    if (parts.port && (parts.port.integerValue <= 0 || parts.port.integerValue > 65535)) return nil;
    NSURLComponents *origin = [NSURLComponents new];origin.scheme = scheme;origin.host = host;
    if (parts.port && parts.port.integerValue != ([scheme isEqual:@"https"] ? 443 : 80)) origin.port = parts.port;
    if (!origin.URL) return nil;
    DVMOrigin *result = [DVMOrigin new];result.URL = origin.URL;return result;
}
- (BOOL)owns:(NSURL *)URL {
    if (!URL || URL.user || URL.password || ![URL.scheme.lowercaseString isEqual:self.URL.scheme.lowercaseString] || ![URL.host.lowercaseString isEqual:self.URL.host.lowercaseString]) return NO;
    NSInteger standard = [self.URL.scheme isEqual:@"https"] ? 443 : 80;
    return (URL.port ? URL.port.integerValue : standard) == (self.URL.port ? self.URL.port.integerValue : standard);
}
- (NSURL *)startURL { return [[NSURL URLWithString:@"/vereinsverwaltung/?view=dashboard" relativeToURL:self.URL] absoluteURL]; }
+ (BOOL)externalURL:(NSURL *)URL {
    if (!URL || URL.user || URL.password) return NO;
    NSString *scheme = URL.scheme.lowercaseString;
    return ([scheme isEqual:@"https"] && URL.host.length) || [scheme isEqual:@"mailto"] || [scheme isEqual:@"tel"];
}
@end
