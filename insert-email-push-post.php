<?php

class InsertEmailPushPost
{
    const POST_TYPE = "posts";

    const REST_NAMESPACE = "insert-email-push-post/v1";

    // ONESIGNAL constants - The data between the brackets need to be replaced with real values
    const OS_PUSH_SEGMENT = "{onesignal_segment_name}";

    const OS_APPID      = "{os_appid}";
    const OS_RESTAPIKEY = "{os_restapikey}";

    // BLUESHIFT constants - The data between the brackets need to be replaced with real values
    const BS_API_URL = 'https://api.getblueshift.com/api/v1/';
    const BS_USER_API_KEY = "{user_api_key}"

    const BS_TRIGGER_NAME   = "{trigger_name}";
    const BS_AUTHOR_EMAIL   = "{author_email}";
    const BS_FROM_NAME      = "{from_name}";
    const BS_FROM_EMAIL     = "{from_email}";
    const BS_REPLY_TO_EMAIL = "{reply_to_email}";

    const BS_SEGMENT_ID = "{segment_id}";
    const BS_ADAPTER_ID = "{adapter_id}"; // sparkpost adapter  

    const BS_TEMPLATE_ID_1  = "{template_id_1}";
    const BS_TEMPLATE_ID_2  = "{template_id_2}";

    /**
     * InsertEmailPushPost constructor.
     */
    public function __construct()
    {
        // Check IEPP_AUTH_KEY is defined - This should be added to the wp-config.php file
        if (!defined('IEPP_AUTH_KEY')) {
            throw new Exception('IEPP_AUTH_KEY must be defined');
        }
        
        add_action('rest_api_init', [$this, 'registerApiEndpoints']);
    }

    /**
     * Register endpoints
     */
    public function registerApiEndpoints()
    {
        register_rest_route(static::REST_NAMESPACE, '/initiate', [
            'methods' => ['POST'],
            'callback' => [$this, 'initiate'],
            'permission_callback' => [$this, 'validate'],
        ]);
    }

    /**
     * @param null $request
     *
     * @return bool
     */
    public function validate($request = null) : bool
    {
        $requestKey = $request->get_header('authorization');

        if (defined('IEPP_AUTH_KEY') && ($requestKey === base64_encode(trim(IEPP_AUTH_KEY, " ")))) {
            return true;
        }

        return false;
    }

    public function initiate(WP_REST_Request $request)
    {
        // Additional params can be added depending on what data needs to be passed through
        $params = [];
        $params['title']   = $request->get_param('title');
        $params['content'] = $request->get_param('content'); // Html format
        $params['author']  = $request->get_param('author'); // Author id
        $params['footer']  = $request->get_param('footer'); // Html format

        $this->addpost($params);    
    }

    /**
     * Update Blueshift template
     *
     * @param array $payload
     * @param int $templateID
     *
     * @return Integer
     */
    private function addpost($params)
    {
        try {

            $author_id = $params['author'];

            $post = [
                "post_type"    => static::POST_TYPE,
                "post_title"   => $params['title'],
                "post_content" => $params['content'],
                "post_status"  => "publish",
                "post_author"  => $author_id
            ];

            $postId = wp_insert_post($post);

            if (!$postId || is_wp_error($postId)) {
                throw new Exception($postId->get_error_message());
            }

            // Add post to a taxonomy term if necessary
            wp_set_object_terms( $postId, '{terms}', '{taxonomy}');

            // Send push notification
            $this->sendPushNotification($postId);

            // Get the email template
            $template_content = file_get_contents('email-template.html');

            // Replace email template shortcodes
            $author_name      = get_the_author_meta( 'display_name', $author_id );
            $template_content = str_replace( "{{author}}", $author_name, $template_content );
            $template_content = str_replace( "{{headline}}", $params['title'], $template_content );
            $template_content = str_replace( "{{content}}", $params['content'], $template_content );
            $template_content = str_replace( "{{footer}}", $params['footer'], $template_content );

            // Update Blueshift Template
            $response = $this->processUpdateTemplate($template_content, $params['title']);

            wp_send_json_success([
                'response' => $response
            ]);  

        } catch (Exception $e) {
            wp_send_json_error([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update Blueshift template
     *
     * @param int $postId
     *
     * @return Integer
     */
    private function sendPushNotification($postId)
    {
        // Request header
        $request_header = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => 'Basic ' . static::OS_RESTAPIKEY
        );

        $request_body = [];
        // Ios badge
        $request_body[ 'ios_badgeType' ] = 'Increase';
        $request_body[ 'ios_badgeCount' ] = 1;

        $request_body[ 'app_id' ] = static::OS_APPID;

        $post = get_post($postId);
        if ($post && !is_wp_error($post)) {
            $request_body['data']['custom_var'] = $postId;
            $request_body['data']['slug']       = $post->post_name;
            $request_body['data']['post_type']  = $post->post_type;

            $request_body[ 'contents' ] = array(
                'en' => $post->post_title
            );
        }

        $request_body[ 'headings' ] = array(
            'en' => $post->post_title
        );

        $request_body[ 'included_segments' ] = array( static::OS_PUSH_SEGMENT );

        // Request for mobile
        $request_body[ 'isIos' ] = true;
        $request_body[ 'isAndroid' ] = true;
        $request_body[ 'isAnyWeb' ] = false;

        // Make request for mobile
        $request = wp_remote_post(
            'https://onesignal.com/api/v1/notifications',
            array(
                'method' => 'POST',
                'headers' => $request_header,
                'body' => json_encode( $request_body )
            )
        );

        // Request for web
        $request_body_web = $request_body;
        $request_body_web[ 'url' ] = get_permalink( $postId );
        $request_body_web[ 'isIos' ] = false;
        $request_body_web[ 'isAndroid' ] = false;
        $request_body_web[ 'isAnyWeb' ] = true;

        // Make request for web
        $request_web = wp_remote_post(
            'https://onesignal.com/api/v1/notifications',
            array(
                'method' => 'POST',
                'headers' => $request_header,
                'body' => json_encode( $request_body_web )
            )
        );

        if ( is_wp_error( $request ) ) {
            return 400;
        }

        $response_body = json_decode($request['body']);

        if ( empty($response_body->errors) ) {
            return 200;
        }
    }

    /**
     * Update Blueshift template
     *
     * @param string $type
     * @param string $template_content
     * @param string $post_title
     *
     * @return Integer
     */
    private function processUpdateTemplate($template_content, $post_title)
    {
        try {
            $template_number = 1;
            $total_templates = 2;
            $option_name = 'bs_template';
            $option_template_number = get_option($option_name);

            if ( $option_template_number ) {
                $option_template_number = intval($option_template_number);
                if ( $option_template_number < $total_templates ) {
                    $template_number = $option_template_number + 1;
                }
                update_option($option_name, $template_number);
            } else {
                add_option($option_name, 1);
            }

            // Join email template and footer template
            $source = $template_content;
            $source = $this->replaceTagsForEmail($source);

            $resource            = new \stdClass();
            $resource->subject   = $post_title;
            $resource->preheader = "{preheader_text}";
            $resource->content   = $source;
            $payload['resource'] = $resource;

            $templateId = constant('self::TEMPLATE_ID_'. $template_number);
            $response = $this->updateTemplate($templateId, $payload);
            $status = $response['response']['code'];

            if ( $status == 200 ) {
                $response = $this->processCreateCampaign($templateId);
            } else {
                $response = 401;
            }

            return $response;

        } catch (Exception $e) {
            wp_send_json_error([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create Blueshift campaign
     *
     * @return string
     */
    private function processCreateCampaign($templateId)
    {
        try {
            $date = date('Y-m-d H:i:s');
            // iso8601 time
            $startDate = date('Y-m-d\TH:i:sP');

            $payload['name'] = "{campaign_name}";
            $payload['author'] = static::BS_AUTHOR_EMAIL;
            $payload['launch'] = true;
            $payload['startdate'] = $startDate;
            $payload['bypass_message_limits'] = true;
            $payload['segment_uuid'] = static::BS_SEGMENT_ID;
            $payload['send_summary_emails'] = static::BS_AUTHOR_EMAIL;

            $trigger = [];
            $triggers = new \stdClass();
            $triggers->trigger_name         = static::BS_TRIGGER_NAME;
            $triggers->template_uuid        = $templateId;
            $triggers->account_adapter_uuid = static::BS_ADAPTER_ID;
            $triggers->from_name            = static::BS_FROM_NAME;
            $triggers->from_address         = static::BS_FROM_EMAIL;
            $triggers->reply_to_address     = static::BS_REPLY_TO_EMAIL;
            $trigger[] = $triggers;
            $payload['triggers'] = $trigger;

            $response = $this->createCampaign($payload);
            $status = $response['response']['code'];
            return $status;

        } catch (Exception $e) {
            wp_send_json_error([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Replace html tags with email formated ones
     *
     * @param string $source
     *
     * @return string
     */
    private function replaceTagsForEmail($source)
    {
        // remove any empty p tags
        $source = str_replace( '<p></p>', '', $source );
        // replace center aligned p tags
        $source = preg_replace( '/(?:<p style="text-align: center;">)([\s\S]+?)(?:<\/p>)/', '<table width="100%"><tr><td style="text-align:center">$1</td></tr></table><br>', $source );
        // replace block editor center aligned p tags
        $source = preg_replace( '/(?:<p\s[^>]*?class\s*=\s*[\\"][^\\"]*?(?:has-text-align-center)[^\\"]*?[\\"][^>]*?>)([\s\S]+?)(?:<\/p>)/', '<table width="100%"><tr><td style="text-align:center">$1</td></tr></table><br>', $source );
        // replace standard p tags
        $source = preg_replace( '/(?:<p>)([\s\S]+?)(?:<\/p>)/', '$1<br><br>', $source );

        // Modify ul tags to apply correct spacing for all email agents
        $source = preg_replace( '/(?:<ul>)([\s\S]+?)(?:<\/ul>)/', '<ul style="margin-top:0px;padding-top:0px;margin-bottom:20px;">$1</ul>', $source );

        return $source;
    }

    /**
     * Update Blueshift template
     *
     * @param array $payload
     * @param int $templateID
     *
     * @return Integer
     */
    private function updateTemplate($templateId, $payload = [])
    {
        if (empty($templateId) || empty($payload)){
            return false;
        }
        $url = static::BS_API_URL . "email_templates/" . $templateId . ".json";

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode(trim(static::BS_USER_API_KEY . ":", " ")),
            'Content-type' => 'application/json'
        );

        $args = array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'method' => 'PUT',
        );
        
        return wp_remote_post($url, $args);
    }

    /**
     * Create Blueshift campaign
     *
     * @param array $payload
     *
     * @return Integer
     */
    private function createCampaign($payload = [])
    {
        if (empty($payload)){
            return false;
        }

        $url = static::BS_API_URL . "campaigns/one_time";

        $headers = [
            'Authorization' => 'Basic ' . base64_encode(trim(static::BS_USER_API_KEY . ":", " ")),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $args = array(
            'headers' => $headers,
            'body' => json_encode($payload),
            'method' => 'POST',
        );
        
        return wp_remote_post($url, $args);
    }
}
