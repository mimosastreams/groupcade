import Foundation

/// Shared between the JS module (phone side) and the external display scene (TV side).
/// All writes happen on the main thread; changes are announced with notifications.
final class GCTvState {
  static let shared = GCTvState()

  static let urlChanged = Notification.Name("GCTvStateURLChanged")
  static let connectedChanged = Notification.Name("GCTvStateConnectedChanged")

  private let lock = NSLock()
  private var _url: URL?
  private var _screens = 0

  /// The page the TV should show (the room's /tv/CODE page), or nil for the idle screen.
  var url: URL? {
    lock.lock(); defer { lock.unlock() }
    return _url
  }

  /// True while an external display (AirPlay or a cable) is attached and showing our scene.
  var connected: Bool {
    lock.lock(); defer { lock.unlock() }
    return _screens > 0
  }

  func setURL(_ string: String?) {
    let next = string.flatMap { URL(string: $0) }
    DispatchQueue.main.async {
      self.lock.lock()
      let same = self._url == next
      self._url = next
      self.lock.unlock()
      if !same {
        NotificationCenter.default.post(name: GCTvState.urlChanged, object: nil)
      }
    }
  }

  /// Called by the scene delegate on the main thread.
  func screenAttached(_ attached: Bool) {
    lock.lock()
    _screens = max(0, _screens + (attached ? 1 : -1))
    lock.unlock()
    NotificationCenter.default.post(name: GCTvState.connectedChanged, object: nil)
  }
}
