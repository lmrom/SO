#include <iostream>
#include <string>
#include <ctime>
#include <cstdlib>
#include <fstream>
#include <unistd.h>
#include <algorithm>
#include <cstdio> // Necesario para FILE*, fopen, fgets, fputc

using namespace std;

// ======================================================
// CONFIGURACIÓN FINAL - AJUSTA TUS IPs
// ======================================================
const string IP_PI      = "10.13.160.78";   // IP de tu Raspberry Pi
const string IP_ESP32   = "10.13.160.38";   // IP de tu cámara
const string USER_PI    = "lmr";            // Tu usuario en la Pi
const string RUTA_PI    = "/var/www/html/"; // Donde vive el index.php
const string PUERTO_SERIAL = "/dev/ttyACM0"; // Tu puerto de Arduino
const string FILE_WHITE = "whitelist.txt";  // Tu lista de UIDs
// ======================================================

//Para la foto no :

string obtenerFechaArchivo() {
    time_t ahora = time(0);
    struct tm tstruct;
    char buf[80];
    tstruct = *localtime(&ahora);
    strftime(buf, sizeof(buf), "%Y-%m-%d_%H-%M-%S", &tstruct);
    return string(buf);
}

//para el log

string obtenerFechaLog() {
    time_t ahora = time(0);
    struct tm tstruct;
    char buf[80];
    tstruct = *localtime(&ahora);
    strftime(buf, sizeof(buf), "%d/%m/%Y %H:%M:%S", &tstruct);
    return string(buf);
}

bool estaEnWhitelist(string uid) {
    ifstream archivo(FILE_WHITE);
    string linea;
    
    // Limpieza estricta como en tu código funcional
    uid.erase(remove_if(uid.begin(), uid.end(), ::isspace), uid.end());
    
    if (!archivo.is_open()) return false;
    while (getline(archivo, linea)) {
        linea.erase(remove_if(linea.begin(), linea.end(), ::isspace), linea.end());
        if (linea == uid) return true;
    }
    return false;
}


void procesarAcceso(string uid, bool autorizado, FILE* serial) {
    string fechaArchivo = obtenerFechaArchivo();
    string fechaLog = obtenerFechaLog();    
   
    string estado = autorizado ? "AUTORIZADO" : "DENEGADO";
    string subCarpeta = autorizado ? "autorizado/" : "denegado/";
    char comandoLed = autorizado ? '1' : '2'; 
    
    string nombreFoto = "foto_" + fechaArchivo + ".jpg";

    cout << "\n[" << fechaLog << "] Tarjeta: " << uid << " [" << estado << "]" << endl;

    // 1. Activar LED correspondiente usando el método clásico de C (fputc)
    cout << ">> Enviando señal al Arduino: " << comandoLed << endl;
    fputc(comandoLed, serial); 
    fflush(serial); // Fuerza la salida inmediata

    // 2. Tomar foto
    cout << ">> Capturando foto de la ESP32-CAM..." << endl;
    string cmdFoto = "curl -s http://" + IP_ESP32 + " -o " + nombreFoto;
    system(cmdFoto.c_str());

    // 3. Enviar foto a la Raspberry Pi (SCP)
    cout << ">> Sincronizando evidencia con la Pi..." << endl;
    string cmdEnviarFoto = "scp " + nombreFoto + " " + USER_PI + "@" + IP_PI + ":" + RUTA_PI + "fotos/" + subCarpeta;
    system(cmdEnviarFoto.c_str());

    // 4. Actualizar Log en la Pi (SSH)
    string registro = estado + " | " + fechaLog + " | UID: " + uid + " | Foto: " + "fotos/" + subCarpeta + nombreFoto;
    string cmdLog = "ssh " + USER_PI + "@" + IP_PI + " \"echo '" + registro + "' >> " + RUTA_PI + "log.txt\"";
    system(cmdLog.c_str());

    // 5. Borrar foto local
    remove(nombreFoto.c_str());

    cout << ">>> OPERACIÓN COMPLETADA CON ÉXITO <<<" << endl;
}

int main() {
    // Usamos la configuración stty corta y efectiva de tu código de prueba
    string configSerial = "stty -F " + PUERTO_SERIAL + " 115200 raw -echo";
    system(configSerial.c_str());

    // Abrimos el puerto al estilo C puro
    FILE* serial = fopen(PUERTO_SERIAL.c_str(), "r+");
    if (!serial) {
        cerr << "ERROR: No se pudo abrir el Arduino. Revisa el puerto " << PUERTO_SERIAL << endl;
        return 1;
    }

    cout << ">>> AUDITORÍA INTEGRAL ACTIVA <<<" << endl;

    char buffer[100];
    // Leemos con fgets igual que en tu código funcional
    while (fgets(buffer, sizeof(buffer), serial)) {
        string uidLeido(buffer);
        
        // Limpiamos los saltos de línea de la lectura serial
        uidLeido.erase(remove(uidLeido.begin(), uidLeido.end(), '\n'), uidLeido.end());
        uidLeido.erase(remove(uidLeido.begin(), uidLeido.end(), '\r'), uidLeido.end());
        
        if (uidLeido.length() > 1) {
            if (estaEnWhitelist(uidLeido)) {
                procesarAcceso(uidLeido, true, serial);
            } else {
                procesarAcceso(uidLeido, false, serial);
            }
        }
        usleep(100000); // 0.1s para estabilidad
    }

    fclose(serial);
    return 0;
}

