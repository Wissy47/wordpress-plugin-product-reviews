<?php
/**
 * Plugin Name: Simple Reviews
 * Description: A simple WordPress plugin that registers a custom post type for product reviews and provides REST API support.
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_Reviews {
    public function __construct() {
        add_action('init', [$this, 'register_product_review_cpt']); 
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_shortcode('product_reviews', [$this, 'display_product_reviews']);      
    }

 
    public function register_product_review_cpt() {
        register_post_type('product_review', [
            'labels'      => [
                'name'          => 'Product Reviews',
                'singular_name' => 'Product Review'
            ],
            'public'      => true,
            'supports'    => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
        ]);
    }

    public function register_rest_routes() {
        register_rest_route('mock-api/v1', '/sentiment/', [
            'methods'  => 'POST',
            'callback' => [$this, 'analyze_sentiment'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('mock-api/v1', '/review-history/', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_review_history'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('mock-api/v1', '/outliers/', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_review_outliers'],
            'permission_callback' => '__return_true',
        ]);

    }

    public function analyze_sentiment($request) {
        $params = $request->get_json_params();
        $text = isset($params['text']) ? sanitize_text_field($params['text']) : '';
        
        if (empty($text)) {
            return new WP_Error('empty_text', 'No text provided for analysis.', ['status' => 400]);
        }

        $sentiment_scores = ['positive' => 0.9, 'negative' => 0.2, 'neutral' => 0.5];
        $random_sentiment = array_rand($sentiment_scores);
        return rest_ensure_response(['sentiment' => $random_sentiment, 'score' => $sentiment_scores[$random_sentiment]]);
    }

    public function get_review_history() {
        $reviews = get_posts([
            'post_type'      => 'product_review',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        
        $response = [];
        foreach ($reviews as $review) {
            $response[] = [
                'id'       => $review->ID,
                'title'    => $review->post_title,
                'sentiment'=> get_post_meta($review->ID, 'sentiment', true) ?? 'neutral',
                'score'    => get_post_meta($review->ID, 'sentiment_score', true) ?? 0.5,
            ];
        }

        return rest_ensure_response($response);
    }

    // Outlier callback
    public function get_review_outliers(){
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare("SELECT `post_id`, `meta_value` FROM $wpdb->postmeta WHERE meta_key = %s", 'sentiment_score'));
        $scores = array_column( $results, 'meta_value');
        $outliers = $this->calculate_outliers($scores);

        if (is_bool($outliers) && !$outliers) {
            return new WP_Error('no_outliers_found', 'No outliers found.', ['status' => 404]);
        }

        $outliers_posts = [];
        foreach ($results as $key => $value) {
            if (in_array($value->meta_value, $outliers)) {
               $outliers_posts[] =  $value->post_id;
            }
        }
        $reviews = get_posts([
            'post_type'      => 'product_review',
            'posts_per_page' => 5,
            'post__in'       =>$outliers_posts,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $response = [];
        foreach ($reviews as $review) {
            $response[] = [
                'id'       => $review->ID,
                'title'    => $review->post_title,
                'sentiment'=> get_post_meta($review->ID, 'sentiment', true) ?? 'neutral',
                'score'    => get_post_meta($review->ID, 'sentiment_score', true) ?? 0.5,
            ];
        }
        return rest_ensure_response($response);
    }

    public function calculate_outliers($data) {
            // Sort the data
        sort($data);

            // Calculate Q1 and Q3
        $q1 = $this->calculate_percentile($data, 25);
        $q3 = $this->calculate_percentile($data, 75);

            // Calculate IQR
        $iqr = $q3 - $q1;

            // Calculate lower and upper bounds
        $lowerBound = $q1 - 1.5 * $iqr;
        $upperBound = $q3 + 1.5 * $iqr;

            // Identify outliers
        $outliers = array();
        foreach ($data as $value) {
            if ($value < $lowerBound || $value > $upperBound) {
                $outliers[] = $value;
            }
        }
        if (empty($outliers)) {
                return false;
        }
        return $outliers;
    }

    public function calculate_percentile($data, $percentile) {
            $index = ($percentile / 100) * (count($data) - 1);
            $lowerIndex = floor($index);
            $upperIndex = ceil($index);
            $weight = $index - $lowerIndex;

            if ($lowerIndex == $upperIndex) {
                return $data[$lowerIndex];
            } else {
                return $data[$lowerIndex] + ($weight * ($data[$upperIndex] - $data[$lowerIndex]));
            }
    }

    public function display_product_reviews() {
        $reviews = get_posts([
            'post_type'      => 'product_review',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $output = '<style>
            .review-positive { color: green; font-weight: bold; }
            .review-negative { color: red; font-weight: bold; }
        </style>';

        $output .= '<ul>';
        foreach ($reviews as $review) {
            $sentiment = get_post_meta($review->ID, 'sentiment', true) ?? 'neutral';
            $class = ($sentiment === 'positive') ? 'review-positive' : (($sentiment === 'negative') ? 'review-negative' : '');
            $output .= "<li class='$class'>{$review->post_title} (Sentiment: $sentiment)</li>";
        }
        $output .= '</ul>';

        return $output;
    }
}

new Simple_Reviews();
