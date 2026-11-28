// Adds settings that come from the build environment to app.json.
//
// GROUPCADE_CAST_APP_ID: the Google Cast receiver app ID (the same one as Settings > Groupcade on the
// site). With it, the app can cast the TV page to a Chromecast or Google TV. Without it, the Cast
// library still loads (with Google's default receiver) but the app hides Cast.
//
// Host sign-in (optional accounts):
// - Sign in with Apple: the capability and the native module.
// - Google sign-in: on iPhone it needs a URL scheme made from the iOS client ID. Put the ID in
//   app.json at expo.extra.googleIosClientId (or the GOOGLE_IOS_CLIENT_ID build variable). While it
//   is empty the app hides the Google button on iPhone; Apple and email still work.
module.exports = ({ config }) => {
  const castAppId = (process.env.GROUPCADE_CAST_APP_ID || '').replace(/[^A-Za-z0-9]/g, '');
  const iosClient = (process.env.GOOGLE_IOS_CLIENT_ID || config.extra?.googleIosClientId || '').trim();
  const plugins = [
    ...(config.plugins || []),
    ['react-native-google-cast', { receiverAppId: castAppId || 'CC1AD845' }],
    'expo-secure-store',
    'expo-apple-authentication',
  ];
  if (iosClient) {
    const id = iosClient.replace(/\.apps\.googleusercontent\.com$/, '');
    plugins.push(['@react-native-google-signin/google-signin', { iosUrlScheme: `com.googleusercontent.apps.${id}` }]);
  }
  return {
    ...config,
    ios: { ...config.ios, usesAppleSignIn: true },
    plugins,
    extra: { ...(config.extra || {}), castAppId, googleIosClientId: iosClient },
  };
};
