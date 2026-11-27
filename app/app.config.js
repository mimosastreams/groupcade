// Adds settings that come from the build environment to app.json.
// GROUPCADE_CAST_APP_ID: the Google Cast receiver app ID (the same one as Settings > Groupcade on the
// site). With it, the app can cast the TV page to a Chromecast or Google TV. Without it, the Cast
// library still loads (with Google's default receiver) but the app hides Cast.
module.exports = ({ config }) => {
  const castAppId = (process.env.GROUPCADE_CAST_APP_ID || '').replace(/[^A-Za-z0-9]/g, '');
  return {
    ...config,
    plugins: [...(config.plugins || []), ['react-native-google-cast', { receiverAppId: castAppId || 'CC1AD845' }]],
    extra: { ...(config.extra || {}), castAppId },
  };
};
