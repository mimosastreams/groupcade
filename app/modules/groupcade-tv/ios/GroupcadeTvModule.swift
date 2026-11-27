import ExpoModulesCore
import UIKit

/// JS side of the TV: tell the TV which page to show, and hear when a TV connects or leaves.
public final class GroupcadeTvModule: Module {
  private var observer: NSObjectProtocol?

  public func definition() -> ModuleDefinition {
    Name("GroupcadeTv")

    Events("onTvChange")

    OnCreate {
      // Keep the scene delegate class linked in: iOS looks it up by name from Info.plist.
      _ = GCExternalDisplaySceneDelegate.self
      self.observer = NotificationCenter.default.addObserver(
        forName: GCTvState.connectedChanged, object: nil, queue: .main
      ) { [weak self] _ in
        self?.sendEvent("onTvChange", ["connected": GCTvState.shared.connected])
      }
      // iOS 27+ only gives the app a TV scene once a view controller registers a scene accessory.
      GroupcadeTvModule.registerAccessorySoon(tries: 20)
    }

    OnDestroy {
      if let observer = self.observer {
        NotificationCenter.default.removeObserver(observer)
      }
    }

    /// True while a TV (AirPlay or cable) is showing the TV window.
    Function("isConnected") { () -> Bool in
      GCTvState.shared.connected
    }

    /// What iOS sees, for the "TV check" line in the app: whether this build can get a TV scene,
    /// and which scenes and screens are attached right now.
    Function("diagnostics") { () -> [String: Any] in
      GroupcadeTvModule.readDiagnostics()
    }

    /// The page the TV shows: the room's TV URL, or null for the idle screen.
    Function("setRoomUrl") { (url: String?) in
      GCTvState.shared.setURL(url)
      DispatchQueue.main.async { GroupcadeTvModule.ensureAccessory() }
    }
  }

  /// Reads UIKit state on the main thread (sync functions run on the JS thread).
  static func readDiagnostics() -> [String: Any] {
    if Thread.isMainThread {
      return diagnosticsOnMain()
    }
    var out: [String: Any] = [:]
    DispatchQueue.main.sync {
      out = diagnosticsOnMain()
    }
    return out
  }

  // MARK: iOS 27 scene accessory

  /// "registered", "waiting" (no main window yet), "not needed" (before iOS 27) or "old SDK"
  /// (built with an Xcode that has no scene accessory API, so iOS 27 phones will mirror).
  static var accessoryState = "waiting"
  private static var accessoryRegistration: AnyObject?
  private static weak var accessoryHost: UIViewController?

  static func registerAccessorySoon(tries: Int) {
    DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
      ensureAccessory()
      if accessoryState == "waiting" && tries > 0 {
        registerAccessorySoon(tries: tries - 1)
      }
    }
  }

  /// The app's main window root view controller: the React Native root, which stays presented.
  private static func mainRootViewController() -> UIViewController? {
    for scene in UIApplication.shared.connectedScenes {
      guard let windowScene = scene as? UIWindowScene, scene.session.role == .windowApplication else { continue }
      let window = windowScene.windows.first(where: { $0.isKeyWindow }) ?? windowScene.windows.first
      if let root = window?.rootViewController { return root }
    }
    return nil
  }

  /// Registers the TV scene accessory on the main root view controller (main thread only).
  /// Safe to call often: it does nothing once registered on the current root.
  static func ensureAccessory() {
    #if compiler(>=6.4)
    if #available(iOS 27.0, *) {
      guard let root = mainRootViewController() else {
        accessoryState = "waiting"
        return
      }
      if accessoryRegistration != nil && accessoryHost === root { return }
      if let old = accessoryRegistration as? UISceneAccessoryRegistration, let host = accessoryHost {
        host.unregisterSceneAccessory(old)
      }
      let configuration = UISceneConfiguration(name: "Groupcade TV", sessionRole: .windowExternalDisplayNonInteractive)
      configuration.delegateClass = GCExternalDisplaySceneDelegate.self
      accessoryRegistration = root.registerSceneAccessory(.externalNonInteractive(sceneConfiguration: configuration))
      accessoryHost = root
      accessoryState = "registered"
    } else {
      accessoryState = "not needed"
    }
    #else
    accessoryState = "old SDK"
    #endif
  }

  private static func accessoryAvailable() -> Bool? {
    #if compiler(>=6.4)
    if #available(iOS 27.0, *), let reg = accessoryRegistration as? UISceneAccessoryRegistration {
      return reg.isAvailable
    }
    #endif
    return nil
  }

  private static func diagnosticsOnMain() -> [String: Any] {
    ensureAccessory()
    let manifest = Bundle.main.object(forInfoDictionaryKey: "UIApplicationSceneManifest") as? [String: Any]
    let configs = manifest?["UISceneConfigurations"] as? [String: Any] ?? [:]
    let multiple: Bool = (manifest?["UIApplicationSupportsMultipleScenes"] as? Bool) ?? false
    let tvRole: Bool = configs["UIWindowSceneSessionRoleExternalDisplayNonInteractive"] != nil
    let linked: Bool = NSClassFromString("GCExternalDisplaySceneDelegate") != nil
    var roles: [String] = []
    for scene in UIApplication.shared.connectedScenes {
      roles.append(scene.session.role.rawValue)
    }
    var out: [String: Any] = [:]
    out["multipleScenes"] = multiple
    out["tvRole"] = tvRole
    out["delegateLinked"] = linked
    out["scenes"] = roles
    out["screens"] = UIScreen.screens.count
    out["connected"] = GCTvState.shared.connected
    out["accessory"] = accessoryState
    if let available = accessoryAvailable() { out["accessoryAvailable"] = available }
    out["ios"] = UIDevice.current.systemVersion
    return out
  }
}
