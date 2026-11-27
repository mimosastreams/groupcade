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

  private static func diagnosticsOnMain() -> [String: Any] {
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
    return out
  }
}
