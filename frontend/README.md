# Margin LMS Frontend

From this folder:

```powershell
flutter pub get
flutter run -d chrome --web-hostname localhost --web-port 5173 --dart-define=API_BASE_URL=http://localhost:8000
```

Run Laravel using the same hostname family:

```powershell
php artisan serve --host=localhost --port=8000
```



