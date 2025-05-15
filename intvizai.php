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
            document.getElementById('intvizai-result').innerHTML = `
                <p>Oto Twoje wygenerowane zdjęcie:</p>
                <img src="data:image/png;base64,${data.image_base64}" style="max-width: 100%;">
                <a href="data:image/png;base64,${data.image_base64}" download="intvizai_result.png">Pobierz obraz</a>
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

    if (!isset($_FILES['image'])) {
        wp_send_json_error(['message' => 'Nie przesłano obrazu.']);
    }

    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';

    if (!$api_key) {
        wp_send_json_error(['message' => 'Brak zdefiniowanej stałej OPENAI_API_KEY w wp-config.php.']);
    }    

    $image_path = $_FILES['image']['tmp_name'];
    
    require_once ABSPATH . WPINC . '/class-requests.php'; // tylko jeśli poza WordPressem

    $api_key = OPENAI_API_KEY;
    
    $body = [
        [
            'name' => 'image',
            'filename' => 'image.png',
            'type' => 'image/png',
            'contents' => fopen($image_path, 'r')
        ],
        [
            'name' => 'prompt',
            'contents' => 'Generate a photorealistic interior visualization. The output file should match exactly the input image.'
        ],
        [
            'name' => 'n',
            'contents' => '1'
        ],
        [
            'name' => 'size',
            'contents' => '1024x1024'
        ],
        [
            'name' => 'response_format',
            'contents' => 'b64_json'
        ]
    ];
    
    $response = Requests::request(
        'https://api.openai.com/v1/images/edits',
        [
            'Authorization' => 'Bearer ' . $api_key
        ],
        $body,
        'POST',
        [
            'type' => 'multipart',
            'timeout' => 60
        ]
    );
    
    $data = json_decode($response->body, true);
    
    if (!isset($data['data'][0]['b64_json'])) {
        error_log('####################### OpenAI Request Body: #######################');
        error_log(print_r($body, true));
    
        error_log('####################### OpenAI Response: #######################');
        error_log(print_r($response, true));
        
        wp_send_json_error(['message' => 'Brak obrazu w odpowiedzi API.', 'debug' => $data]);
    }
    
    wp_send_json_success(['image_base64' => $data['data'][0]['b64_json']]);

    // Zwiększ licznik i zapisz
    $meta['count'] += 1;
    update_user_meta($user_id, 'intvizai_count', $meta);

    wp_send_json_success(['image_base64' => $data['data'][0]['b64_json']]);
}
