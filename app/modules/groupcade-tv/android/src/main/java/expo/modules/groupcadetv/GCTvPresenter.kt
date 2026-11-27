package expo.modules.groupcadetv

import android.app.Activity
import android.app.Presentation
import android.content.Context
import android.graphics.Color
import android.hardware.display.DisplayManager
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.Display
import android.view.WindowManager
import android.webkit.WebView
import android.webkit.WebViewClient
import java.lang.ref.WeakReference

/** Keeps one Presentation on the first presentation display, showing the current room's TV page. */
object GCTvPresenter {
  private const val BG = 0xFF15122B.toInt()
  private val main = Handler(Looper.getMainLooper())
  private var activity: WeakReference<Activity>? = null
  private var displays: DisplayManager? = null
  private var presentation: TvPresentation? = null
  private var url: String? = null
  @Volatile private var lastConnected = false

  /** Last state sent to JS; safe to read from any thread. */
  val lastKnown: Boolean
    get() = lastConnected

  var onChange: ((Boolean) -> Unit)? = null

  val connected: Boolean
    get() = presentation?.isShowing == true

  private val listener = object : DisplayManager.DisplayListener {
    override fun onDisplayAdded(displayId: Int) = update()
    override fun onDisplayRemoved(displayId: Int) = update()
    override fun onDisplayChanged(displayId: Int) = update()
  }

  fun attach(next: Activity) {
    if (activity?.get() === next) return
    detach()
    activity = WeakReference(next)
    val dm = next.getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
    displays = dm
    dm.registerDisplayListener(listener, main)
    update()
  }

  fun detach() {
    displays?.unregisterDisplayListener(listener)
    displays = null
    dismiss()
    activity = null
    notifyIfChanged()
  }

  fun setUrl(next: String?) {
    if (next == url) return
    url = next
    presentation?.show(url)
  }

  private fun update() {
    val act = activity?.get()
    val target: Display? = displays?.getDisplays(DisplayManager.DISPLAY_CATEGORY_PRESENTATION)?.firstOrNull()
    if (act == null || act.isFinishing || target == null) {
      dismiss()
    } else if (presentation?.display?.displayId != target.displayId) {
      dismiss()
      try {
        val p = TvPresentation(act, target)
        p.setOnDismissListener { if (presentation === p) { presentation = null; notifyIfChanged() } }
        p.show()
        p.show(url)
        presentation = p
      } catch (e: WindowManager.InvalidDisplayException) {
        presentation = null
      }
    }
    notifyIfChanged()
  }

  private fun dismiss() {
    val p = presentation ?: return
    presentation = null
    try { p.dismiss() } catch (_: Exception) {}
  }

  private fun notifyIfChanged() {
    val now = connected
    if (now != lastConnected) {
      lastConnected = now
      onChange?.invoke(now)
    }
  }

  private class TvPresentation(context: Context, display: Display) : Presentation(context, display) {
    private var web: WebView? = null
    private var loaded: String? = "\u0000"
    private val retry = Runnable { loaded?.let { if (it.isNotEmpty()) web?.loadUrl(it) } }

    override fun onCreate(savedInstanceState: Bundle?) {
      super.onCreate(savedInstanceState)
      val w = WebView(context)
      w.setBackgroundColor(BG)
      w.settings.javaScriptEnabled = true
      w.settings.domStorageEnabled = true
      w.settings.mediaPlaybackRequiresUserGesture = false
      w.webViewClient = object : WebViewClient() {
        @Suppress("OVERRIDE_DEPRECATION")
        override fun onReceivedError(view: WebView?, errorCode: Int, description: String?, failingUrl: String?) {
          // Wi-Fi hiccup or a slow first load: try again rather than leave the TV blank.
          main.removeCallbacks(retry)
          main.postDelayed(retry, 4000)
        }
      }
      web = w
      setContentView(w)
    }

    fun show(url: String?) {
      val next = url ?: ""
      if (next == loaded) return
      loaded = next
      main.removeCallbacks(retry)
      val w = web ?: return
      if (next.isEmpty()) {
        w.loadDataWithBaseURL(null, IDLE, "text/html", "utf-8", null)
      } else {
        w.loadUrl(next)
      }
    }

    override fun onStop() {
      main.removeCallbacks(retry)
      super.onStop()
    }
  }

  private val IDLE = """
    <!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>html,body{margin:0;height:100%;background:#15122B;color:#fff;font-family:sans-serif}
    body{display:flex;align-items:center;justify-content:center;text-align:center}
    h1{font-size:8vw;margin:0 0 2vw;font-weight:900}p{font-size:2.6vw;margin:0;opacity:.75}</style></head>
    <body><div><h1>Groupcade</h1><p>Make a room on your phone and the game shows up here.</p></div></body></html>
  """.trimIndent()
}
