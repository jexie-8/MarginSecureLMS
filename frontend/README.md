# Margin LMS Frontend

Flutter Web frontend for Margin LMS.

## Run

Start the Laravel backend on `http://localhost:8000`, then run:

```powershell
flutter pub get
flutter run -d edge --web-hostname localhost --web-port 5173 --no-web-resources-cdn --dart-define=API_BASE_URL=http://localhost:8000
```

Open `http://localhost:5173`.

## Checks

```powershell
flutter analyze
flutter test
```

## Build

```powershell
flutter build web --release --no-web-resources-cdn --dart-define=API_BASE_URL=https://api.example.edu
```

