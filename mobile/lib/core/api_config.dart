import 'dart:io' show Platform;

/// API base URL and paths for the YAWOTE customer API.
/// All requests use [baseUrl] + [apiPath] + endpoint (e.g. login -> POST baseUrl/api/customer/login).
class ApiConfig {
  ApiConfig._();

  /// Override at build/run time, e.g.
  /// `flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000`
  ///
  /// On **Android emulator**, `127.0.0.1` / `localhost` are rewritten to `10.0.2.2` (host machine).
  /// On a **physical phone**, use your PC's LAN IP (e.g. `http://192.168.1.5:8000`) — `127.0.0.1` is the device itself.
  static const String _envBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://green.smartsoft.co.tz',
  );

  /// Base URL for the API (no trailing slash).
  static String get baseUrl => _resolvedBaseUrl(_envBaseUrl);

  static String _resolvedBaseUrl(String raw) {
    var url = raw.trim();
    if (url.endsWith('/')) {
      url = url.substring(0, url.length - 1);
    }
    if (!Platform.isAndroid) return url;
    final uri = Uri.tryParse(url);
    if (uri == null || !uri.hasAuthority) return url;
    final host = uri.host.toLowerCase();
    if (host == '127.0.0.1' || host == 'localhost') {
      return uri.replace(host: '10.0.2.2').toString();
    }
    return url;
  }

  /// Path prefix for customer API (no leading slash).
  static const String apiPath = 'api/customer';

  /// Full base URL for customer API endpoints.
  /// Example: https://epm.smartsoft.co.tz/api/customer
  static String get customerApiBase => '$baseUrl/$apiPath';

  /// Convenience: full URL for a customer API endpoint.
  /// [path] should not start with / (e.g. 'login', 'profile', 'loans').
  static String customerUrl(String path) {
    final p = path.startsWith('/') ? path.substring(1) : path;
    final url = '$customerApiBase/$p';
    // Debug: Print URL to verify it's using the correct domain
    print('API URL: $url');
    return url;
  }

  /// User-friendly message for network/API errors.
  static String networkErrorMessage(Object error, [String fallback = 'Hitilafu ya mtandao']) {
    final msg = error.toString().toLowerCase();
    if (msg.contains('socket') || msg.contains('connection') || msg.contains('connection refused') || msg.contains('failed host lookup')) {
      return 'Hauwezi kuunganisha. Angalia mtandao au anwani ya seva.';
    }
    if (error is FormatException || msg.contains('format') || msg.contains('json')) {
      return 'Majibu ya seva si sahihi.';
    }
    if (msg.contains('timeout') || msg.contains('timed out')) {
      return 'Muda umekwisha. Jaribu tena.';
    }
    if (msg.contains('handshake') || msg.contains('certificate') || msg.contains('ssl')) {
      return 'Hitilafu ya usalama wa mtandao.';
    }
    return fallback;
  }
}
