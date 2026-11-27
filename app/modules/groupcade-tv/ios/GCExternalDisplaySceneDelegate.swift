import UIKit
import WebKit

/// Scene delegate for the TV. iOS uses it when an external display connects (AirPlay Screen
/// Mirroring or a cable), because Info.plist lists it under
/// UIWindowSceneSessionRoleExternalDisplayNonInteractive (added by this module's config plugin).
/// Instead of mirroring the phone, the TV then gets its own window: the room's /tv/CODE page,
/// while the phone keeps showing the host controls.
@objc(GCExternalDisplaySceneDelegate)
public final class GCExternalDisplaySceneDelegate: UIResponder, UIWindowSceneDelegate {
  public var window: UIWindow?

  public func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
    guard let windowScene = scene as? UIWindowScene else { return }
    let window = UIWindow(windowScene: windowScene)
    window.rootViewController = GCTvViewController()
    window.backgroundColor = .black
    window.isHidden = false
    self.window = window
    GCTvState.shared.screenAttached(true)
  }

  public func sceneDidDisconnect(_ scene: UIScene) {
    window?.isHidden = true
    window = nil
    GCTvState.shared.screenAttached(false)
  }
}

/// The TV window: a web view of the room's TV page, or an idle screen when no room is open.
final class GCTvViewController: UIViewController, WKNavigationDelegate {
  private var webView: WKWebView!
  private let idle = UIStackView()
  private var loaded: URL?
  private var retry: DispatchWorkItem?

  override func viewDidLoad() {
    super.viewDidLoad()
    view.backgroundColor = UIColor(red: 0x15 / 255, green: 0x12 / 255, blue: 0x2B / 255, alpha: 1)

    let config = WKWebViewConfiguration()
    config.allowsInlineMediaPlayback = true
    config.mediaTypesRequiringUserActionForPlayback = []
    config.websiteDataStore = .default()
    config.applicationNameForUserAgent = "GroupcadeTV"
    webView = WKWebView(frame: view.bounds, configuration: config)
    webView.autoresizingMask = [.flexibleWidth, .flexibleHeight]
    webView.isOpaque = false
    webView.backgroundColor = .clear
    webView.scrollView.backgroundColor = .clear
    webView.scrollView.isScrollEnabled = false
    webView.scrollView.contentInsetAdjustmentBehavior = .never
    webView.navigationDelegate = self
    webView.isHidden = true
    view.addSubview(webView)

    let title = UILabel()
    title.text = "Groupcade"
    title.font = .systemFont(ofSize: 96, weight: .black)
    title.textColor = .white
    let sub = UILabel()
    sub.text = "Make a room in the Groupcade app on your iPhone to start."
    sub.font = .systemFont(ofSize: 36, weight: .semibold)
    sub.textColor = UIColor(red: 0xAB / 255, green: 0xA7 / 255, blue: 0xCC / 255, alpha: 1)
    sub.numberOfLines = 0
    sub.textAlignment = .center
    idle.axis = .vertical
    idle.alignment = .center
    idle.spacing = 24
    idle.addArrangedSubview(title)
    idle.addArrangedSubview(sub)
    idle.translatesAutoresizingMaskIntoConstraints = false
    view.addSubview(idle)
    NSLayoutConstraint.activate([
      idle.centerXAnchor.constraint(equalTo: view.centerXAnchor),
      idle.centerYAnchor.constraint(equalTo: view.centerYAnchor),
      idle.widthAnchor.constraint(lessThanOrEqualTo: view.widthAnchor, multiplier: 0.8),
    ])

    NotificationCenter.default.addObserver(self, selector: #selector(urlChanged), name: GCTvState.urlChanged, object: nil)
    urlChanged()
  }

  deinit {
    NotificationCenter.default.removeObserver(self)
    retry?.cancel()
  }

  @objc private func urlChanged() {
    let url = GCTvState.shared.url
    guard url != loaded else { return }
    loaded = url
    retry?.cancel()
    if let url = url {
      webView.isHidden = false
      idle.isHidden = true
      webView.load(URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 20))
    } else {
      webView.isHidden = true
      idle.isHidden = false
      webView.loadHTMLString("", baseURL: nil)
    }
  }

  // Wi-Fi hiccup or a slow first load: try again in a few seconds rather than leave the TV blank.
  func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { scheduleRetry() }
  func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) { scheduleRetry() }
  func webViewWebContentProcessDidTerminate(_ webView: WKWebView) { scheduleRetry() }

  private func scheduleRetry() {
    retry?.cancel()
    let work = DispatchWorkItem { [weak self] in
      guard let self = self, let url = self.loaded else { return }
      self.webView.load(URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 20))
    }
    retry = work
    DispatchQueue.main.asyncAfter(deadline: .now() + 3, execute: work)
  }
}
