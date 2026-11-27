// Config plugin: give the TV its own scene instead of mirroring the phone.
// Adds an external display scene configuration to Info.plist that points at
// GCExternalDisplaySceneDelegate (ios/GCExternalDisplaySceneDelegate.swift).
// Expo SDK 58's iOS template already uses the scene life cycle (UIApplicationSceneManifest with
// a SceneDelegate), so this only adds a second role next to the app's own.
const { withInfoPlist } = require('expo/config-plugins');

const ROLE = 'UIWindowSceneSessionRoleExternalDisplayNonInteractive';

module.exports = function withGroupcadeTv(config) {
  return withInfoPlist(config, (cfg) => {
    const plist = cfg.modResults;
    const manifest = plist.UIApplicationSceneManifest;
    if (!manifest || !manifest.UISceneConfigurations) {
      throw new Error(
        'groupcade-tv: Info.plist has no UIApplicationSceneManifest. This plugin expects the scene-based ' +
          'iOS template (Expo SDK 58+). Adding a scene manifest to an app-delegate-only app would break its main window.'
      );
    }
    manifest.UISceneConfigurations[ROLE] = [
      {
        UISceneConfigurationName: 'Groupcade TV',
        UISceneDelegateClassName: 'GCExternalDisplaySceneDelegate',
      },
    ];
    return cfg;
  });
};
