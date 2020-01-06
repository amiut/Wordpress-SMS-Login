# Wordpress-SMS-Login
A very simple sms login method

## Get the OTP
`/wp-json/otp-login/v1/login/` **POST**

| Parameter     | Description   |
| ------------- |:-------------:|
| identifier    | Mobile number or email address |
| **Response**  | JSON: 6 digit otp code |


## Verify and login
`/wp-json/otp-login/v1/verify/` **POST**

| Parameter     | Description   |
| ------------- |:-------------:|
| identifier    | Mobile number or email address |
| otp           | OTP Code |
| **Response**  | JSON: login success or failure |
