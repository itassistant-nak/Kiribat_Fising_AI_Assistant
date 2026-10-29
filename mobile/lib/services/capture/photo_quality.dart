import 'dart:typed_data';

import 'package:image/image.dart' as img;

/// Verdict of the on-device photo clarity check.
enum PhotoQualityStatus {
  /// Sharp enough for identification.
  clear,

  /// Too blurry — identification would be unreliable, retake advised.
  blurry,

  /// Could not judge (undecodable bytes, or image too small).
  unknown,
}

/// Result of the on-device clarity check. Pure Dart: no server, no network,
/// works offline at sea — unlike the Laravel host, which currently has no
/// image decoder (no GD/Imagick/ffmpeg), so this runs where the photo is
/// actually taken.
class PhotoQuality {
  final PhotoQualityStatus status;

  /// Variance of the Laplacian (higher = sharper). Null when unknown.
  final double? blurScore;

  const PhotoQuality(this.status, this.blurScore);

  bool get retakeRecommended => status == PhotoQualityStatus.blurry;
}

/// Sharpness below this Laplacian variance is called blurry.
/// Calibrated 2026-10-28 on real/demo photos (512px grayscale):
/// sharp fish photo 1406.8, light blur (r=2) 179.3, medium blur (r=5) 36.1,
/// heavy blur (r=10) 10.8, blank frame 0.0. Threshold 100 sits in the wide
/// gap between usable (≥179) and degraded (≤36) with >1.8x margins.
const double kBlurThreshold = 100.0;

/// Assess clarity of raw image bytes (JPEG/PNG). Never throws.
PhotoQuality assessPhotoQuality(Uint8List bytes) {
  try {
    if (bytes.isEmpty) return const PhotoQuality(PhotoQualityStatus.unknown, null);
    final decoded = img.decodeImage(bytes);
    if (decoded == null) return const PhotoQuality(PhotoQualityStatus.unknown, null);

    final score = laplacianVariance(decoded);
    if (score == null) return const PhotoQuality(PhotoQualityStatus.unknown, null);

    return PhotoQuality(
      score < kBlurThreshold ? PhotoQualityStatus.blurry : PhotoQualityStatus.clear,
      score,
    );
  } catch (_) {
    return const PhotoQuality(PhotoQualityStatus.unknown, null);
  }
}

/// Variance-of-Laplacian sharpness on a ≤512px grayscale copy.
/// Returns null for degenerate images (<3px in either dimension).
double? laplacianVariance(img.Image image) {
  final small = image.width > 512 ? img.copyResize(image, width: 512) : image;
  final gray = img.grayscale(small.clone());
  final w = gray.width, h = gray.height;
  if (w < 3 || h < 3) return null;

  double px(int x, int y) => gray.getPixel(x, y).r.toDouble();

  var sum = 0.0;
  var sumSq = 0.0;
  var n = 0;
  for (var y = 1; y < h - 1; y++) {
    for (var x = 1; x < w - 1; x++) {
      final lap = -4 * px(x, y) + px(x - 1, y) + px(x + 1, y) + px(x, y - 1) + px(x, y + 1);
      sum += lap;
      sumSq += lap * lap;
      n++;
    }
  }
  if (n == 0) return null;
  final mean = sum / n;
  return sumSq / n - mean * mean;
}
