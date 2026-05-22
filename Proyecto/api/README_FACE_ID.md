# Revisión Automática en Laptop (C++)

La Raspberry **no procesa rostros**. Solo envía imágenes a tu laptop.

## Flujo

1. `acceso.php` (Raspberry) crea acceso en `PENDIENTE`.
2. Al adjuntar foto, si el modo está en `AUTO`, `acceso.php` envía:
   - foto de referencia (`foto_url` desde DB)
   - foto capturada actual
3. El server C++ en laptop responde JSON (`match`, `score`, `threshold`).
4. `acceso.php` resuelve:
   - match => `APROBADA`
   - no match => `DENEGADA`
   - si falla => permanece manual.

## Archivos

- `acceso.php`: cliente HTTP desde Raspberry hacia laptop.
- `face_verify_server_cpp.cpp`: servidor C++ de verificación facial en laptop.
- `index.php`: switch `MANUAL/AUTO`.

## 1) Compilar server C++ en laptop

Dependencias:

```bash
sudo apt update
sudo apt install -y g++ pkg-config libopencv-dev libcpp-httplib-dev
```

Compilar:

```bash
g++ -O2 -std=c++17 face_verify_server_cpp.cpp -o face_verify_server_cpp \
  $(pkg-config --cflags opencv4) \
  -lopencv_core -lopencv_imgproc -lopencv_objdetect -lopencv_imgcodecs -lopencv_features2d
```

## 2) Ejecutar server C++ en laptop

```bash
export FACE_VERIFY_PORT=5050
export FACE_VERIFY_TOKEN=""
./face_verify_server_cpp
```

Health check en laptop:

```bash
curl http://localhost:5050/health
```

## 3) Configurar Raspberry (`acceso.php`)

Opciones de configuración (recomendado por variables de entorno en PHP-FPM/Nginx):

- `FACE_VERIFY_REMOTE_URL` = `http://IP_LAPTOP:5050/verify`
- `FACE_VERIFY_REMOTE_TOKEN` = mismo token (o vacío)
- `FACE_VERIFY_THRESHOLD` = umbral (ej. `0.58`)
- `FACE_VERIFY_TIMEOUT_SEG` = timeout de comparación (ej. `8`)

Si no usas variables de entorno, cambia los `const` al inicio de `acceso.php`.

Importante: si dejas `TU_LAPTOP_IP`, el dashboard bloqueará `AUTO` con motivo `FACE_VERIFY_REMOTE_URL_PLACEHOLDER`.

Luego recarga Nginx:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## 4) Verificar desde Raspberry

```bash
curl http://IP_LAPTOP:5050/health
curl -X POST http://localhost/acceso.php -d "accion=OBTENER_MODO_REVISION"
```

Si `auto_disponible=true`, ya puedes activar `AUTO` en `index.php`.

Si `auto_disponible=false`, ahora la API regresa `auto_diagnostico.motivo` para saber exactamente qué falta (URL, red, curl, health, etc.).
