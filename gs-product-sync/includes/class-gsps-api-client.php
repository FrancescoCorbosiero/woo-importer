<?php
/**
 * API Client for Golden Sneakers API
 *
 * @package GS_Product_Sync
 */

defined('ABSPATH') || exit;

class GSPS_API_Client {

    /**
     * Config array
     *
     * @var array
     */
    private $config;

    /**
     * Constructor
     *
     * @param array $config Configuration array
     */
    public function __construct(array $config) {
        $this->config = $config;
    }

    /**
     * Get API URL with parameters
     *
     * @return string
     */
    private function get_api_url() {
        $params = http_build_query($this->config['api']['params']);
        return $this->config['api']['base_url'] . '?' . $params;
    }

    /**
     * Make API request
     *
     * @param string $url Optional specific URL
     * @return array|WP_Error
     */
    public function request($url = null) {
        $request_url = $url ?: $this->get_api_url();

        $response = wp_remote_get($request_url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['api']['bearer_token'],
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return new WP_Error('api_error', "API returned HTTP {$code}", ['status' => $code]);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Failed to parse JSON response');
        }

        return $data;
    }

    /**
     * Fetch all products
     *
     * @return array|WP_Error
     */
    public function fetch_products() {
        return $this->request();
    }

    /**
     * Test API connection
     *
     * @return array ['success' => bool, 'message' => string, 'count' => int]
     */
    public function test_connection() {
        $result = $this->fetch_products();

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'message' => $result->get_error_message(),
                'count' => 0,
            ];
        }

        if (!is_array($result)) {
            return [
                'success' => false,
                'message' => 'Risposta API non valida',
                'count' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => 'Connessione riuscita',
            'count' => count($result),
        ];
    }
}
