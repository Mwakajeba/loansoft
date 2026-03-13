/// Holds the current user photo URL so dashboard and profile stay in sync.
/// Set on login and when profile uploads a new photo; dashboard reads it.
class UserPhotoHolder {
  UserPhotoHolder._();

  static String? currentPhotoUrl;
}
