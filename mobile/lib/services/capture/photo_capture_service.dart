import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:uuid/uuid.dart';
import 'capture_storage.dart';

/// A fish photograph captured on-device.
class CapturedPhoto {
  /// Absolute path under the app documents directory (survives restarts).
  /// Empty when the platform has no durable filesystem (web preview-only).
  final String localPath;

  /// Display name, e.g. `catch_a1b2c3d4.jpg`.
  final String fileName;

  /// Raw bytes for preview (works on every platform incl. web).
  final Uint8List bytes;

  /// False on web: bytes preview only, nothing persisted to disk.
  final bool persisted;

  const CapturedPhoto({
    required this.localPath,
    required this.fileName,
    required this.bytes,
    this.persisted = true,
  });
}

/// Why a capture attempt produced no photo.
enum CaptureFailure {
  /// User dismissed the camera without taking a photo.
  cancelled,

  /// OS denied the camera permission.
  permissionDenied,

  /// No camera plugin on this platform (e.g. Linux desktop).
  unavailable,

  /// Anything else (storage, plugin crash, ...).
  error,
}

/// Fisher-facing message for a capture failure (pure, unit-tested).
String captureFailureMessage(CaptureFailure failure) {
  switch (failure) {
    case CaptureFailure.cancelled:
      return 'Photo cancelled — no picture taken.';
    case CaptureFailure.permissionDenied:
      return 'Camera permission denied. Allow camera access in system settings, or pick a specimen preset below.';
    case CaptureFailure.unavailable:
      return 'Camera is not available on this device. Pick a specimen preset below instead.';
    case CaptureFailure.error:
      return 'Could not take the photo. Please try again or pick a specimen preset below.';
  }
}

/// Camera capture behind an injectable seam (the screen never touches
/// `ImagePicker` directly, so tests use fakes and the UI stays thin).
abstract class PhotoCaptureService {
  /// Opens the device camera. Returns the captured photo, `null` when the
  /// fisher cancels, or throws [PhotoCaptureException] on failure.
  Future<CapturedPhoto?> takePhoto();
}

class PhotoCaptureException implements Exception {
  final CaptureFailure failure;
  final String? detail;

  const PhotoCaptureException(this.failure, [this.detail]);

  @override
  String toString() => 'PhotoCaptureException($failure${detail != null ? ': $detail' : ''})';
}

class ImagePickerPhotoCapture implements PhotoCaptureService {
  final ImagePicker _picker;

  ImagePickerPhotoCapture({ImagePicker? picker}) : _picker = picker ?? ImagePicker();

  @override
  Future<CapturedPhoto?> takePhoto() async {
    XFile? shot;
    try {
      shot = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: 1600,
        imageQuality: 85,
      );
    } on MissingPluginException {
      throw const PhotoCaptureException(CaptureFailure.unavailable);
    } on PlatformException catch (e) {
      if (e.code == 'camera_access_denied' || e.code == 'photo_access_denied') {
        throw PhotoCaptureException(CaptureFailure.permissionDenied, e.message);
      }
      throw PhotoCaptureException(CaptureFailure.error, e.message);
    }
    if (shot == null) return null;

    final bytes = await shot.readAsBytes();
    final fileName = 'catch_${const Uuid().v4().substring(0, 8)}.jpg';
    // Native: copied into app documents (durable). Web: preview-only (null).
    final persistedPath = await persistCapturedBytes(fileName: fileName, bytes: bytes);

    return CapturedPhoto(
      localPath: persistedPath ?? '',
      fileName: fileName,
      bytes: bytes,
      persisted: persistedPath != null,
    );
  }
}
