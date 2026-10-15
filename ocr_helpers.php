<?php

function ocr_text_length($value) {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/** Intersection area divided by the area of the smaller box (0 when either box is empty). */
function ocr_box_overlap_ratio($first, $second) {
    $intersection_width = max(0, min($first['left'] + $first['width'], $second['left'] + $second['width']) - max($first['left'], $second['left']));
    $intersection_height = max(0, min($first['top'] + $first['height'], $second['top'] + $second['height']) - max($first['top'], $second['top']));
    $smaller_area = min($first['width'] * $first['height'], $second['width'] * $second['height']);
    return $smaller_area > 0 ? $intersection_width * $intersection_height / $smaller_area : 0;
}

function ocr_box_label($region) {
    return $region['left'] . ',' . $region['top'] . ' ' . $region['width'] . 'x' . $region['height'];
}

function run_tesseract_pass($image_file, $page_mode, $coordinate_scale = 1.0) {
    $lines = [];
    exec('tesseract ' . escapeshellarg($image_file) . ' stdout -l rus+eng --psm ' . $page_mode . ' tsv 2>/dev/null', $lines, $status);
    $candidate = $status === 0
        ? select_tesseract_tsv_candidate($lines, $coordinate_scale)
        : select_tesseract_tsv_candidate([]);
    $candidate['psm'] = $page_mode;
    $candidate['scale'] = $coordinate_scale;
    return $candidate;
}

function select_tesseract_tsv_candidate($lines, $coordinate_scale = 1.0) {
    $tokens = [];
    $rejected_tokens = [];
    $raw_word_count = 0;
    foreach (array_slice($lines, 1) as $line) {
        $columns = str_getcsv($line, "\t");
        if ((int)($columns[0] ?? 0) !== 5) continue;
        $word = trim($columns[11] ?? '');
        $confidence = (float)($columns[10] ?? -1);
        if ($word === '') continue;
        $raw_word_count++;
        if ($confidence < 55) {
            $rejected_tokens[] = ['text' => $word, 'confidence' => round($confidence, 1), 'reason' => 'low-confidence'];
            continue;
        }
        if (!preg_match('/[\p{L}\p{N}]{2,}/u', $word)) {
            $rejected_tokens[] = ['text' => $word, 'confidence' => round($confidence, 1), 'reason' => 'not-a-word'];
            continue;
        }

        $token = [
            'text' => $word,
            'confidence' => $confidence,
            'left' => (int)round((int)($columns[6] ?? 0) / $coordinate_scale),
            'top' => (int)round((int)($columns[7] ?? 0) / $coordinate_scale),
            'width' => (int)round((int)($columns[8] ?? 0) / $coordinate_scale),
            'height' => (int)round((int)($columns[9] ?? 0) / $coordinate_scale)
        ];
        if ($token['width'] < 4 || $token['height'] < 6) {
            $rejected_tokens[] = ['text' => $word, 'confidence' => round($confidence, 1), 'reason' => 'tiny-box'];
            continue;
        }
        $tokens[] = $token;
    }

    usort($tokens, function($first, $second) {
        return $second['confidence'] <=> $first['confidence'];
    });
    $unique_tokens = [];
    foreach ($tokens as $token) {
        $duplicate = false;
        foreach ($unique_tokens as $saved) {
            if (ocr_box_overlap_ratio($token, $saved) >= 0.55) {
                $duplicate = true;
                break;
            }
        }
        if (!$duplicate) {
            $unique_tokens[] = $token;
        } else {
            $rejected_tokens[] = ['text' => $token['text'], 'confidence' => round($token['confidence'], 1), 'reason' => 'overlapping-alternative'];
        }
    }

    usort($unique_tokens, function($first, $second) {
        return [$first['top'], $first['left']] <=> [$second['top'], $second['left']];
    });
    $groups = [];
    foreach ($unique_tokens as $token) {
        $matched_group = null;
        foreach ($groups as $group_index => $group) {
            $group_top = min(array_column($group, 'top'));
            $group_bottom = max(array_map(function($item) { return $item['top'] + $item['height']; }, $group));
            $group_left = min(array_column($group, 'left'));
            $group_right = max(array_map(function($item) { return $item['left'] + $item['width']; }, $group));
            $overlap = max(0, min($token['top'] + $token['height'], $group_bottom) - max($token['top'], $group_top));
            $horizontal_gap = $token['left'] > $group_right
                ? $token['left'] - $group_right
                : ($group_left > $token['left'] + $token['width'] ? $group_left - $token['left'] - $token['width'] : 0);
            $maximum_gap = max(24, max($token['height'], $group_bottom - $group_top) * 4);
            if ($overlap / max(1, min($token['height'], $group_bottom - $group_top)) >= 0.35 && $horizontal_gap <= $maximum_gap) {
                $matched_group = $group_index;
                break;
            }
        }
        if ($matched_group === null) $groups[] = [$token];
        else $groups[$matched_group][] = $token;
    }

    $accepted_groups = [];
    $accepted_regions = [];
    $accepted_words = [];
    $confidence_total = 0;
    $confidence_weight = 0;
    foreach ($groups as $group) {
        usort($group, function($first, $second) { return $first['left'] <=> $second['left']; });
        $weight = 0;
        $group_confidence = 0;
        foreach ($group as $token) {
            $token_weight = max(1, ocr_text_length($token['text']));
            $weight += $token_weight;
            $group_confidence += $token['confidence'] * $token_weight;
        }
        $average_confidence = $weight ? $group_confidence / $weight : 0;
        $is_coherent_line = count($group) >= 2 && $average_confidence >= 65;
        $is_confident_single_word = count($group) === 1
            && preg_match('/[\p{L}\p{N}]{4,}/u', $group[0]['text'])
            && $group[0]['confidence'] >= 88;
        if (!$is_coherent_line && !$is_confident_single_word) {
            foreach ($group as $token) {
                $rejected_tokens[] = ['text' => $token['text'], 'confidence' => round($token['confidence'], 1), 'reason' => count($group) === 1 ? 'isolated-word' : 'weak-line'];
            }
            continue;
        }

        $accepted_groups[] = $group;
        foreach ($group as $token) {
            $accepted_words[] = $token['text'];
            $accepted_regions[] = array_intersect_key($token, array_flip(['left', 'top', 'width', 'height']));
            $token_weight = max(1, ocr_text_length($token['text']));
            $confidence_total += $token['confidence'] * $token_weight;
            $confidence_weight += $token_weight;
        }
    }

    $average_confidence = $confidence_weight ? $confidence_total / $confidence_weight : 0;
    return [
        'text' => implode(' ', $accepted_words),
        'regions' => $accepted_regions,
        'word_count' => count($accepted_words),
        'line_count' => count($accepted_groups),
        'raw_word_count' => $raw_word_count,
        'confidence' => round($average_confidence, 1),
        'rejected' => array_slice($rejected_tokens, 0, 20),
        'quality' => count($accepted_groups) * 100 + count($accepted_words) * 20 + ocr_text_length(implode('', $accepted_words)) + $average_confidence
    ];
}

function select_consensus_tesseract_candidate($candidates) {
    usort($candidates, function($first, $second) { return $second['quality'] <=> $first['quality']; });
    foreach ($candidates as $candidate) {
        if ($candidate['word_count'] === 0) continue;
        $confirmed_words = 0;
        foreach ($candidate['regions'] as $region) {
            $confirmed = false;
            foreach ($candidates as $other) {
                if ($candidate === $other) continue;
                $same_pass = ($candidate['psm'] ?? null) === ($other['psm'] ?? null)
                    && abs((float)($candidate['scale'] ?? 1) - (float)($other['scale'] ?? 1)) < 0.01;
                if ($same_pass) continue;
                foreach ($other['regions'] as $other_region) {
                    if (ocr_box_overlap_ratio($region, $other_region) >= 0.2) {
                        $confirmed = true;
                        break 2;
                    }
                }
            }
            if ($confirmed) $confirmed_words++;
        }

        $strong_multword_result = $candidate['word_count'] >= 4 && $candidate['confidence'] >= 85;
        if ($confirmed_words >= min(2, $candidate['word_count']) || $strong_multword_result) {
            $candidate['consensus_confirmed'] = true;
            return $candidate;
        }
    }

    $best = $candidates[0] ?? select_tesseract_tsv_candidate([]);
    foreach ($best['regions'] as $region) {
        $best['rejected'][] = [
            'text' => $region['text'] ?? '',
            'confidence' => $region['confidence'] ?? 0,
            'reason' => 'not-confirmed-by-another-pass'
        ];
    }
    $best['text'] = '';
    $best['regions'] = [];
    $best['word_count'] = 0;
    $best['line_count'] = 0;
    $best['confidence'] = 0;
    $best['quality'] = 0;
    $best['consensus_confirmed'] = false;
    return $best;
}
/** Writes an upscaled grayscale copy of small images to a temp file; returns [file, scale] or null. */
function prepare_upscaled_ocr_image($image_data, $image_info) {
    if (!$image_info || max($image_info[0], $image_info[1]) > 1800 || !function_exists('imagecreatefromstring')) return null;
    $source = @imagecreatefromstring($image_data);
    if (!$source) return null;
    $result = null;
    $scale = min(2, 1800 / max($image_info[0], $image_info[1]));
    if ($scale > 1.05) {
        $width = (int)round($image_info[0] * $scale);
        $height = (int)round($image_info[1] * $scale);
        $prepared = imagecreatetruecolor($width, $height);
        imagecopyresampled($prepared, $source, 0, 0, 0, 0, $width, $height, $image_info[0], $image_info[1]);
        imagefilter($prepared, IMG_FILTER_GRAYSCALE);
        imagefilter($prepared, IMG_FILTER_CONTRAST, -15);
        $prepared_file = tempnam(sys_get_temp_dir(), 'meme_ocr_');
        if ($prepared_file !== false && imagepng($prepared, $prepared_file)) {
            $result = [$prepared_file, $scale];
        }
        imagedestroy($prepared);
    }
    imagedestroy($source);
    return $result;
}

/**
 * Applies $paint to every padded OCR region and returns the PNG-encoded result.
 * $create_painter receives the GD image and returns fn($image, $left, $top, $right, $bottom).
 */
function paint_ocr_regions($image_data, $regions, $padding_ratio, $min_padding, $max_padding, callable $create_painter) {
    if (!$regions || !function_exists('imagecreatefromstring') || !function_exists('imagefilter')) return $image_data;
    $image = @imagecreatefromstring($image_data);
    if (!$image) return $image_data;
    $image_width = imagesx($image);
    $image_height = imagesy($image);
    $padding = max($min_padding, min($max_padding, (int)(min($image_width, $image_height) * $padding_ratio)));
    $paint = $create_painter($image);

    foreach ($regions as $region) {
        $left = max(0, $region['left'] - $padding);
        $top = max(0, $region['top'] - $padding);
        $right = min($image_width, $region['left'] + $region['width'] + $padding);
        $bottom = min($image_height, $region['top'] + $region['height'] + $padding);
        if ($right - $left < 1 || $bottom - $top < 1) continue;
        $paint($image, $left, $top, $right, $bottom);
    }

    ob_start();
    imagepng($image);
    $painted_image = ob_get_clean();
    imagedestroy($image);
    return $painted_image !== false && $painted_image !== '' ? $painted_image : $image_data;
}

/** Covers OCR regions with dark boxes and orange outlines (interactive analysis). */
function mask_ocr_regions($image_data, $regions) {
    return paint_ocr_regions($image_data, $regions, 0.01, 4, 12, function($image) {
        $mask_color = imagecolorallocate($image, 8, 8, 8);
        $outline_color = imagecolorallocate($image, 255, 145, 40);
        return function($image, $left, $top, $right, $bottom) use ($mask_color, $outline_color) {
            imagefilledrectangle($image, $left, $top, $right - 1, $bottom - 1, $mask_color);
            imagerectangle($image, $left, $top, $right - 1, $bottom - 1, $outline_color);
        };
    });
}

/** Gaussian-blurs OCR regions (background parser). */
function blur_ocr_regions($image_data, $regions) {
    return paint_ocr_regions($image_data, $regions, 0.02, 8, 24, function() {
        return function($image, $left, $top, $right, $bottom) {
            $width = $right - $left;
            $height = $bottom - $top;
            $crop = imagecreatetruecolor($width, $height);
            imagecopy($crop, $image, 0, 0, $left, $top, $width, $height);
            imagefilter($crop, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($crop, IMG_FILTER_GAUSSIAN_BLUR);
            imagecopy($image, $crop, $left, $top, 0, 0, $width, $height);
            imagedestroy($crop);
        };
    });
}
