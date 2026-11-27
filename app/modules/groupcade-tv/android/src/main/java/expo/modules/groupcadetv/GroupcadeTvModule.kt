package expo.modules.groupcadetv

import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

/**
 * Android side of the TV screen: when a second display is attached (USB-C/HDMI adapter, a dock, or a
 * wireless display that Android offers as a presentation display), it shows the room's /tv/CODE page
 * there while the phone keeps the host controls. Same JS API as the iOS module.
 */
class GroupcadeTvModule : Module() {
  override fun definition() = ModuleDefinition {
    Name("GroupcadeTv")
    Events("onTvChange")

    OnCreate {
      GCTvPresenter.onChange = { connected -> sendEvent("onTvChange", mapOf("connected" to connected)) }
    }

    OnActivityEntersForeground {
      appContext.currentActivity?.let { GCTvPresenter.attach(it) }
    }

    OnDestroy {
      GCTvPresenter.onChange = null
      GCTvPresenter.detach()
    }

    // Sync functions run on the JS thread: read the last known state here, and do the UI work
    // (attaching to the activity, loading the page) on the main thread.
    Function("isConnected") {
      appContext.currentActivity?.let { act -> act.runOnUiThread { GCTvPresenter.attach(act) } }
      GCTvPresenter.lastKnown
    }

    Function("setRoomUrl") { url: String? ->
      val act = appContext.currentActivity
      android.os.Handler(android.os.Looper.getMainLooper()).post {
        act?.let { GCTvPresenter.attach(it) }
        GCTvPresenter.setUrl(url)
      }
    }
  }
}
