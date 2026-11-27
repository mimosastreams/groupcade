// Config plugin: give the TV its own scene instead of mirroring the phone.
// Adds an external display scene configuration to Info.plist that points at
// GCExternalDisplaySceneDelegate (ios/GCExternalDisplaySceneDelegate.swift).
// Expo SDK 58's iOS template already uses the scene life cycle (UIApplicationSceneManifest with
// a SceneDelegate), so this adds a second role next to the app's own and turns on multiple scenes.
const { withAppDelegate, withInfoPlist } = require('expo/config-plugins');

const ROLE = 'UIWindowSceneSessionRoleExternalDisplayNonInteractive';
const MARK = '// groupcade-tv: TV scene';

// Also answer iOS directly when a TV connects, instead of relying only on Info.plist lookup.
const APP_DELEGATE_METHOD = `
  ${MARK}. When an AirPlay or HDMI screen connects, give it the Groupcade TV scene instead of a
  // mirror of the phone. Other sessions get the app's normal scene from Info.plist.
  @objc(application:configurationForConnectingSceneSession:options:)
  func application(
    _ application: UIApplication,
    configurationForConnecting connectingSceneSession: UISceneSession,
    options: UIScene.ConnectionOptions
  ) -> UISceneConfiguration {
    if connectingSceneSession.role == .windowExternalDisplayNonInteractive,
      let tv = NSClassFromString("GCExternalDisplaySceneDelegate") {
      let config = UISceneConfiguration(name: "Groupcade TV", sessionRole: connectingSceneSession.role)
      config.delegateClass = tv
      return config
    }
    return UISceneConfiguration(name: "Default Configuration", sessionRole: connectingSceneSession.role)
  }
`;

function withTvAppDelegate(config) {
  return withAppDelegate(config, (cfg) => {
    const d = cfg.modResults;
    if (d.language !== 'swift' || d.contents.includes(MARK)) return cfg;
    let src = d.contents;
    // Insert the method at the end of the AppDelegate class body.
    const start = src.search(/class AppDelegate\b[^{]*\{/);
    if (start < 0) {
      console.warn('groupcade-tv: could not find class AppDelegate; TV scene method not added.');
      return cfg;
    }
    let depth = 0;
    let end = -1;
    for (let i = src.indexOf('{', start); i < src.length; i++) {
      if (src[i] === '{') depth++;
      else if (src[i] === '}' && --depth === 0) {
        end = i;
        break;
      }
    }
    if (end < 0) return cfg;
    src = src.slice(0, end) + APP_DELEGATE_METHOD + src.slice(end);
    if (!/^import UIKit$/m.test(src)) src = 'import UIKit\n' + src;
    d.contents = src;
    return cfg;
  });
}

module.exports = function withGroupcadeTv(config) {
  config = withTvAppDelegate(config);
  return withInfoPlist(config, (cfg) => {
    const plist = cfg.modResults;
    const manifest = plist.UIApplicationSceneManifest;
    if (!manifest) {
      // EAS CLI reads the config before there is an ios/ folder (introspection), against a bare
      // Info.plist with no scene manifest. Nothing to add then; the real prebuild uses the SDK 58
      // template, which has one. Never add a manifest ourselves: on an app-delegate-only app that
      // would break its main window.
      if (!(cfg.modRequest && cfg.modRequest.introspect)) {
        console.warn('groupcade-tv: Info.plist has no UIApplicationSceneManifest, so the TV scene was not added.');
      }
      return cfg;
    }
    // iOS only hands an app its own external display scene if the app declares it supports more
    // than one scene. The SDK 58 template sets this to false, and then AirPlay just mirrors the
    // phone. On iPhone this flag only allows the TV scene; it doesn't add any windows on the phone.
    manifest.UIApplicationSupportsMultipleScenes = true;
    manifest.UISceneConfigurations = manifest.UISceneConfigurations || {};
    manifest.UISceneConfigurations[ROLE] = [
      {
        UISceneConfigurationName: 'Groupcade TV',
        UISceneDelegateClassName: 'GCExternalDisplaySceneDelegate',
      },
    ];
    return cfg;
  });
};
