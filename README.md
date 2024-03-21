# WordPress Endpoint - Insert Post, push notification, send email

The code in this repository is responsible for inserting a WordPress post, sending a BlueShift email and firing a OneSignal push notification all via one endpoint.

## Endpoint requirements

* **URL:** https://{website_name}/wp-json/insert-email-push-post/v1/initiate
* **Request:** POST
* **Headers:**
```bash
Authorization: base64_encode(trim(IEPP_AUTH_KEY, " "))
Content-Type: application/json
```
* **Body:**
```bash
{
    "title": "{title}",
    "content": "{content}",
    "author": "{author}",
    "footer": "{footer}"
}
```