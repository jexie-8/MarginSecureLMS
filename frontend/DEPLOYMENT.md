# Margin LMS Flutter Web deployment

Build with the production Laravel origin:

`flutter build web --release --dart-define=API_BASE_URL=https://api.example.edu`

Deploy `build/web` behind HTTPS. Configure the web server to serve `index.html` for unknown routes so direct links such as `/reset-password` and `/detail/courses/1` work. Do not cache `index.html`; fingerprinted assets may be cached for one year. Keep the frontend and API on the same parent domain so secure SameSite cookie sessions work reliably.

Before release run `flutter analyze`, `flutter test`, and browser smoke tests at phone, tablet, laptop, and wide-desktop sizes for every role. Verify login, MFA, password reset, uploads/downloads, offline feedback, keyboard navigation, focus visibility, and screen-reader labels.
