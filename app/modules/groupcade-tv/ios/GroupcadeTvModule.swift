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
      let manifest = Bundle.main.object(forInfoDictionaryKey: "UIApplicationSceneManifest") as? [String: Any]
      let configs = manifest?["UISceneConfigurations"] as? [String: Any] ?? [:]
      return [
        "multipleScenes": manifest?["UIApplicationSupportsMultipleScenes"] as? Bool ?? false,
        "tvRole": configs["UIWindowSceneSessionRoleExternalDisplayNonInteractive"] != nil,
        "delegateLinked": NSClassFromString("GCExternalDisplaySceneDelegate") != nil,
        "scenes": UIApplication.shared.connectedScenes.map { $0.session.role.rawValue },
        "screens": UIScreen.screens.count,
        "connected": GCTvState.shared.connected,
      ]
    }.runOnQueue(.main)

    /// The page the TV shows: the room's TV URL, or null for the idle screen.
    Function("setRoomUrl") { (url: String?) in
      GCTvState.shared.setURL(url)
    }
  }
}
