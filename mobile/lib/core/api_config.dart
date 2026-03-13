/// API base URL and paths for the YAWOTE customer API.
/// All requests use [baseUrl] + [apiPath] + endpoint (e.g. login -> POST baseUrl/api/customer/login).
class ApiConfig {
  ApiConfig._();

  /// Base URL for the API (no trailing slash).
  /// Production: https://epm.smartsoft.co.tz
  /// For local testing use e.g. http://10.0.2.2:8000 (Android emulator) or http://YOUR_IP:8000
  static const String baseUrl = 'https://green.smartsoft.co.tz';

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
