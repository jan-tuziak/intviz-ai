<?php
/*
Plugin Name: IntVizAI Image Editor
Description: Allow logged-in users to upload an image, process it via OpenAI, and download results (max 2 per day).
Version: 1.0
Author: Jan Tuziak
*/

add_shortcode('intvizai', 'intvizai_shortcode');

function intvizai_shortcode() {
    if (!is_user_logged_in()) {
        return "<h2>Musisz być zalogowany, aby korzystać z tej funkcji.</h2>";
    }

    ob_start();
    ?>
    <form id="intvizai-form" enctype="multipart/form-data">
        <input type="file" id="intvizai-image" name="image" accept="image/*" required>
        <button type="submit">Wyślij obraz do AI</button>
    </form>
    <div id="intvizai-result"></div>

    <script>
    document.getElementById('intvizai-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const fileInput = document.getElementById('intvizai-image');
        const formData = new FormData();
        formData.append('action', 'intvizai_process');
        formData.append('image', fileInput.files[0]);

        const res = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        });

        const data = await res.json();
        if (data.success) {
            const base64 = data.image_base64;
            const dataUrl = `data:image/png;base64,${base64}`;
            
            document.getElementById('intvizai-result').innerHTML = `
                <p>Oto Twoje wygenerowane zdjęcie:</p>
                <img src="${dataUrl}" style="max-width: 100%;">
                <a href="${dataUrl}" download="intvizai_result.png">Pobierz obraz</a>
            `;
        } else {
            document.getElementById('intvizai-result').textContent = 'Błąd: ' + data.data.message;
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_intvizai_process', 'intvizai_process');

function intvizai_process() {
    $user_id = get_current_user_id();
    if (!current_user_can('manage_options')) {
        $today = date('Y-m-d');
    
        // Pobierz zapisany licznik
        $meta = get_user_meta($user_id, 'intvizai_count', true);
        $meta = is_array($meta) ? $meta : ['count' => 0, 'date' => $today];
    
        // Jeśli data ≠ dziś, resetuj licznik
        if ($meta['date'] !== $today) {
            $meta['count'] = 0;
            $meta['date'] = $today;
        }
    
        if ($meta['count'] >= 2) {
            wp_send_json_error(['message' => 'Osiągnąłeś dzienny limit 2 obrazów. Spróbuj jutro!']);
        }
    }
    
    if (!isset($_FILES['image'])) {
        wp_send_json_error(['message' => 'Nie przesłano obrazu.']);
    }

    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

    if (!$api_key) {
        wp_send_json_error(['message' => 'Brak zdefiniowanej stałej OPENAI_API_KEY w wp-config.php.']);
    }    

    $image_path_original  = $_FILES['image']['tmp_name'];
    // $image_path = convert_image_to_openai_png($image_path_original);
    $image_path = $image_path_original;
    
    $ch = curl_init();
   
    $ch_options = [
        CURLOPT_URL => 'https://api.openai.com/v1/images/edits',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile($image_path, 'image/png', 'image.png'),
            'prompt' => 'Generate a photorealistic visualization of the attached image. The result image should match the attached image exactly.',
            'n' => 1,
            'model' => 'gpt-image-1',
            'quality' => 'high'
        ],
        CURLOPT_TIMEOUT => 300,
    ];
    
    curl_setopt_array($ch, $ch_options);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        wp_send_json_error(['message' => 'cURL error: ' . curl_error($ch)]);
    }
    
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (!isset($data['data'][0]['b64_json'])) {
        // error_log('####################### OpenAI Request cURL Options: #######################');
        // error_log(print_r($ch_options, true));
    
        // error_log('####################### OpenAI Response: #######################');
        // error_log(print_r($response, true));
        
        wp_send_json_error(['message' => 'Brak obrazu w odpowiedzi API.', 'debug' => $data]);
    } else {
        if (!current_user_can('manage_options')){   
            $meta['count'] += 1;
            update_user_meta($user_id, 'intvizai_count', $meta);
        }
        
        wp_send_json([
            'success' => true,
            'data' => [
                'image_base64' => $data['data'][0]['b64_json']
            ]
        ], 200, JSON_UNESCAPED_SLASHES);
    }
}

function convert_image_to_openai_png($input_path) {
    $original = imagecreatefromstring(file_get_contents($input_path));

    // Utwórz nowy obraz 1024x1024 z kanałem alfa
    $converted = imagecreatetruecolor(1024, 1024);
    imagesavealpha($converted, true);
    $transparent = imagecolorallocatealpha($converted, 0, 0, 0, 127);
    imagefill($converted, 0, 0, $transparent);

    // Wyrównaj oryginał proporcjonalnie do 1024x1024
    $src_width = imagesx($original);
    $src_height = imagesy($original);
    $dst_width = 1024;
    $dst_height = 1024;

    // Zachowanie proporcji
    $ratio = min($dst_width / $src_width, $dst_height / $src_height);
    $new_width = (int)($src_width * $ratio);
    $new_height = (int)($src_height * $ratio);

    $dst_x = (int)(($dst_width - $new_width) / 2);
    $dst_y = (int)(($dst_height - $new_height) / 2);

    imagecopyresampled($converted, $original, $dst_x, $dst_y, 0, 0, $new_width, $new_height, $src_width, $src_height);

    // Zapisz do pliku tymczasowego
    $tmp_path = tempnam(sys_get_temp_dir(), 'openai_') . '.png';
    imagepng($converted, $tmp_path);

    imagedestroy($original);
    imagedestroy($converted);

    return $tmp_path;
}
