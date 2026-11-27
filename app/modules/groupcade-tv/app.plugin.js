// Config plugin: give the TV its own scene instead of mirroring the phone.
// Adds an external display scene configuration to Info.plist that points at
// GCExternalDisplaySceneDelegate (ios/GCExternalDisplaySceneDelegate.swift).
// Expo SDK 58's iOS template already uses the scene life cycle (UIApplicationSceneManifest with
// a SceneDelegate), so this adds a second role next to the app's own and turns on multiple scenes.
const { withInfoPlist } = require('expo/config-plugins');

const ROLE = 'UIWindowSceneSessionRoleExternalDisplayNonInteractive';

module.exports = function withGroupcadeTv(config) {
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
