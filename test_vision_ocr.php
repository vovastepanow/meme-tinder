<?php

/**
 * Test script for the new read_image_text_local function using Qwen3.5 vision model
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/ocr_helpers.php';
require_once __DIR__ . '/meme_analysis.php';

// Create a simple test image with text (larger size)
function create_test_image() {
    // Create a larger 800x600 white image
    $img = imagecreatetruecolor(800, 600);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $red = imagecolorallocate($img, 255, 0, 0);
    $blue = imagecolorallocate($img, 0, 0, 255);
    
    // Fill with white
    imagefill($img, 0, 0, $white);
    
    // Add some text in the center with larger font
    $font_sizes = [40, 50, 60];
    $texts = ["Hello World", "Test 123", "OCR Test"];
    $text_colors = [$black, $red, $blue];
    
    $y_start = 150;
    foreach ($font_sizes as $i => $font_size) {
        $text = $texts[$i];
        $text_color = $text_colors[$i];
        
        // Get bounding box for text
        $bbox = @imagettfbbox($font_size, 0, $font_size, $text);
        if ($bbox) {
            $x = min($bbox[2], $bbox[4]);
            $y = min($bbox[1], $bbox[3]);
            $x += ($bbox[0] - $bbox[2]) / 2;
            $y += ($bbox[1] - $bbox[3]) / 2;
            
            // Draw text with slight background
            $bg_color = imagecolorallocate($img, 240, 240, 240);
            imagettftext($img, $font_size, 0, $x, $y + $font_size, $bg_color, $font_size, $text);
            imagettftext($img, $font_size, 0, $x, $y, $text_color, $font_size, $text);
            
            $y_start += $font_size + 20;
        }
    }
    
    // Add some geometric shapes for visual interest
    $center_x = 400;
    $center_y = 300;
    
    // Draw a simple circle
    $circle_radius = 50;
    $circle_color = imagecolorallocate($img, 200, 200, 200);
    imagefilledellipse($img, $center_x, $center_y, 2 * $circle_radius, 2 * $circle_radius, $circle_color);
    
    // Draw a smaller circle inside
    $inner_circle_radius = 30;
    $inner_circle_color = imagecolorallocate($img, 100, 100, 100);
    imagefilledellipse($img, $center_x, $center_y, 2 * $inner_circle_radius, 2 * $inner_circle_radius, $inner_circle_color);
    
    // Output as PNG
    ob_start();
    imagepng($img);
    $result = ob_get_clean();
    
    imagedestroy($img);
    
    return $result;
}

echo "=== Testing read_image_text_local with Qwen3.5 Vision Model ===\n\n";

// Create test image
$image_data = create_test_image();
echo "Test image created, size: " . strlen($image_data) . " bytes\n\n";

// Test the new read_image_text_local function
echo "Calling read_image_text_local()...\n\n";

try {
    $ocr_result = read_image_text_local($image_data);
    echo "OCR Result: '" . substr($ocr_result, 0, 200) . (strlen($ocr_result) > 200 ? '...' : '') . "'\n\n";
    
    // Check if it's not empty
    if (!empty($ocr_result)) {
        echo "✓ Text was successfully extracted!\n";
        echo "Text length: " . strlen($ocr_result) . " characters\n";
    } else {
        echo "⚠ Text extraction returned empty (may be expected if vision model didn't find text)\n";
    }
    
    // Test with regions capture
    echo "\n=== Testing with regions capture ===\n";
    $best_regions = null;
    $ocr_meta = null;
    $ocr_result2 = read_image_text_local($image_data, $best_regions, true, $ocr_meta);
    
    echo "OCR Result: '" . substr($ocr_result2, 0, 200) . (strlen($ocr_result2) > 200 ? '...' : '') . "'\n";
    echo "Status: " . ($ocr_meta['status'] ?? 'unknown') . "\n";
    echo "Image Width: " . ($ocr_meta['image_width'] ?? 0) . "\n";
    echo "Image Height: " . ($ocr_meta['image_height'] ?? 0) . "\n";
    echo "Description: " . substr($ocr_meta['description'] ?? '', 0, 200) . "...\n";
    echo "Has Text: " . ($ocr_meta['has_text'] ?? 'unknown') . "\n";
    echo "Regions count: " . count($best_regions) . "\n";
    if (!empty($best_regions)) {
        echo "First region: left=" . ($best_regions[0]['left'] ?? 'N/A') . ", top=" . ($best_regions[0]['top'] ?? 'N/A') . "\n";
        echo "First region text: " . ($best_regions[0]['text'] ?? 'N/A') . "\n";
    }
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";