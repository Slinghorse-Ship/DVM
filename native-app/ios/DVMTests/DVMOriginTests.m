#import <XCTest/XCTest.h>
#import "DVMOrigin.h"
#import "DVMViewController.h"

@interface DVMOriginTests : XCTestCase
@end
@implementation DVMOriginTests
- (void)testNativeAddressCorrectionRetainsTheOriginalInput {
    XCTAssertTrue(NSThread.isMainThread);
    XCTAssertNil([NSUserDefaults.standardUserDefaults stringForKey:@"dvm-origin"],@"A fresh owned app profile is required; preserve existing settings");
    if ([NSUserDefaults.standardUserDefaults stringForKey:@"dvm-origin"]) return;
    UIWindowScene *scene = nil;
    for (UIScene *candidate in UIApplication.sharedApplication.connectedScenes) {
        if ([candidate isKindOfClass:UIWindowScene.class] && candidate.activationState == UISceneActivationStateForegroundActive) {scene = (UIWindowScene *)candidate;break;}
    }
    XCTAssertNotNil(scene); if (!scene) return;
    UIWindow *previous = nil;
    for (UIWindow *candidate in scene.windows) if (candidate.isKeyWindow) previous = candidate;
    UIWindow *window = [[UIWindow alloc] initWithWindowScene:scene];
    DVMViewController *controller = [DVMViewController new];
    window.rootViewController = [[UINavigationController alloc] initWithRootViewController:controller];
    [window makeKeyAndVisible];
    @try {
        NSPredicate *shown = [NSPredicate predicateWithBlock:^BOOL(id object, NSDictionary *bindings){return [controller.presentedViewController isKindOfClass:UIAlertController.class] && controller.presentedViewController.view.window != nil;}];
        XCTestExpectation *ready = [[XCTNSPredicateExpectation alloc] initWithPredicate:shown object:controller];
        [self waitForExpectations:@[ready] timeout:10];
        UIAlertController *dialog = (UIAlertController *)controller.presentedViewController;
        XCTAssertTrue([dialog isKindOfClass:UIAlertController.class]); if (![dialog isKindOfClass:UIAlertController.class]) return;
        UITextField *field = dialog.textFields.firstObject; UIAlertAction *open = dialog.actions.lastObject;
        XCTAssertNotNil(field); XCTAssertFalse(open.enabled);
        XCTAssertEqualObjects(field.accessibilityLabel,NSLocalizedString(@"native_club_address",nil));
        for (NSString *invalid in @[@"http://club.example.invalid/original?keep=ä",@"https://person:original@club.example.invalid/"]) {
            field.text = invalid; [field sendActionsForControlEvents:UIControlEventEditingChanged];
            XCTAssertFalse(open.enabled); XCTAssertEqualObjects(field.text,invalid);
            XCTAssertEqualObjects(dialog.message,NSLocalizedString(@"native_enter_your_club_https_address_without_a_path_credentials_or_",nil));
            XCTAssertEqual(controller.presentedViewController,dialog);
        }
        NSString *corrected = @" HTTPS://Club.Example.Invalid:443/ ";
        field.text = corrected; [field sendActionsForControlEvents:UIControlEventEditingChanged];
        XCTAssertTrue(open.enabled); XCTAssertEqualObjects(field.text,corrected); XCTAssertNil(dialog.message);
        XCTAssertNil([NSUserDefaults.standardUserDefaults stringForKey:@"dvm-origin"]);
        XCTAssertGreaterThan(controller.view.bounds.size.width,0);
        UIGraphicsImageRenderer *image = [[UIGraphicsImageRenderer alloc] initWithSize:window.bounds.size];
        UIImage *actual = [image imageWithActions:^(UIGraphicsImageRendererContext *context){[window drawViewHierarchyInRect:window.bounds afterScreenUpdates:YES];}];
        XCTAttachment *attachment = [XCTAttachment attachmentWithImage:actual];attachment.name = @"native-address-correction";attachment.lifetime = XCTAttachmentLifetimeKeepAlways;[self addAttachment:attachment];
    } @finally {
        [controller dismissViewControllerAnimated:NO completion:nil];window.hidden = YES;[previous makeKeyWindow];
    }
}
- (void)testSelectedOriginAndOriginalRoutes {
    DVMOrigin *origin = [DVMOrigin originFromString:@" HTTPS://Verein.Example:443/ " allowLocalHTTP:NO];
    XCTAssertNotNil(origin);
    XCTAssertEqualObjects(origin.URL.absoluteString,@"https://verein.example");
    XCTAssertTrue([origin owns:[NSURL URLWithString:@"https://verein.example/vereinsverwaltung/?view=chat&item=original"]]);
    XCTAssertTrue([origin owns:[NSURL URLWithString:@"https://VEREIN.example:443/private-file/original"]]);
    for (NSString *target in @[@"https://verein.example.evil.invalid/",@"https://other.invalid/",@"http://verein.example/",@"https://verein.example:8443/",@"https://user@verein.example/",@"file:///private/key",@"javascript:original"]) XCTAssertFalse([origin owns:[NSURL URLWithString:target]],@"%@",target);
}
- (void)testAmbiguousEntriesAreRejected {
    for (NSString *value in @[@"http://verein.example",@"https://user:password@verein.example",@"https://verein.example/path",@"https://verein.example?token=original",@"https://verein.example#original",@"https://verein.example:0",@"https://verein.example%2f.evil.invalid",@"https://verein.example.",@"javascript:original",@"https://verein.example\\@evil.invalid"]) XCTAssertNil([DVMOrigin originFromString:value allowLocalHTTP:NO],@"%@",value);
}
- (void)testDebugScopeDoesNotBroadenReleaseTransport {
    XCTAssertNotNil([DVMOrigin originFromString:@"http://127.0.0.1:8111" allowLocalHTTP:YES]);
    XCTAssertNil([DVMOrigin originFromString:@"http://127.0.0.1:8111" allowLocalHTTP:NO]);
    XCTAssertNil([DVMOrigin originFromString:@"http://other.invalid" allowLocalHTTP:YES]);
    DVMOrigin *port = [DVMOrigin originFromString:@"https://verein.example:8443" allowLocalHTTP:NO];
    XCTAssertTrue([port owns:[NSURL URLWithString:@"https://verein.example:8443/original"]]);
    XCTAssertFalse([port owns:[NSURL URLWithString:@"https://verein.example/original"]]);
}
- (void)testExternalLaunchDoesNotBecomePrivilegedNavigation {
    for (NSString *value in @[@"https://checkout.stripe.com/original",@"mailto:person@example.invalid",@"tel:+4912345"]) XCTAssertTrue([DVMOrigin externalURL:[NSURL URLWithString:value]]);
    for (NSString *value in @[@"javascript:original",@"intent://original",@"file:///original",@"https://user:secret@other.invalid/"]) XCTAssertFalse([DVMOrigin externalURL:[NSURL URLWithString:value]]);
}

- (void)testFixedNativeInterfaceResourcesUseBothLanguages {
    NSBundle *de = [NSBundle bundleWithPath:[NSBundle.mainBundle pathForResource:@"de" ofType:@"lproj"]];
    NSBundle *en = [NSBundle bundleWithPath:[NSBundle.mainBundle pathForResource:@"en" ofType:@"lproj"]];
    XCTAssertNotNil(de); XCTAssertNotNil(en);
    XCTAssertEqualObjects([de localizedStringForKey:@"native_open" value:nil table:@"Localizable"],@"Öffnen");
    XCTAssertEqualObjects([en localizedStringForKey:@"native_open" value:nil table:@"Localizable"],@"Open");
    XCTAssertEqualObjects([de localizedStringForKey:@"native_your_club" value:nil table:@"Localizable"],@"Dein Verein");
    XCTAssertEqualObjects([en localizedStringForKey:@"native_your_club" value:nil table:@"Localizable"],@"Your club");
    for (NSString *key in @[@"NSCameraUsageDescription",@"NSPhotoLibraryUsageDescription",@"NSBluetoothAlwaysUsageDescription",@"NSLocationWhenInUseUsageDescription",@"NSMicrophoneUsageDescription"]) {
        NSString *german=[de localizedStringForKey:key value:nil table:@"InfoPlist"];
        NSString *english=[en localizedStringForKey:key value:nil table:@"InfoPlist"];
        XCTAssertFalse([german isEqual:key]); XCTAssertFalse([english isEqual:key]); XCTAssertNotEqualObjects(german,english);
    }
}

@end
