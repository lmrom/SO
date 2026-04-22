#include <iostream>
#include <string>
#include <ctime> //fecha y hora
#include <cstdlib> // system() - bin/sh
#include <fstream>
#include <unistd.h> //usleep - t fork()
#include <algorithm> //manejo de strings
#include <cstdio> //archivos


using namespace std;

//CONFIGURACIONES DE IP
const string IP_PI      = "10.13.160.78";
const string IP_ESP32   = "10.13.160.38";
const string USER_PI    = "lmr";
const string RUTA_PI    = "/var/www/html/"; 
const string puerto = "/dev/ttyACM0";
const string white = "whitelist.txt";

//https://stackoverflow.com/questions/997946/how-can-i-get-current-time-and-date-in-c
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
    ifstream archivo(white);  
    string linea;//        inicio    fin         criterio ,  esto se borra   removeif no borra 
    uid.erase(remove_if(uid.begin(), uid.end(), ::isspace), uid.end()); //ALGORITHM
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
    char respuestaLed = autorizado ? '1' : '2'; // char por arduino
    
    string nombreFoto = "foto_" + fechaArchivo + ".jpg";

    cout << "\n[" << fechaLog << "] Tarjeta: " << uid << " [" << estado << "]" << endl;    cout << "Procesando ..."<< endl;
    
    fputc(respuestaLed, serial); //Manda esto al puerto(es archivo)
    fflush(serial); // Fuerza la salida inmediata

    // foto
    cout << ">> Capturando foto" << endl;
    string cmdFoto = "curl -s http://" + IP_ESP32 + " -o " + nombreFoto; // AQUI VIEJO
    system(cmdFoto.c_str());

    // envio
    cout << "Enviando foto a RP" << endl;
    string cmdEnviarFoto = "scp " + nombreFoto + " " + USER_PI + "@" + IP_PI + ":" + RUTA_PI + "fotos/" + subCarpeta;//ip rp
    system(cmdEnviarFoto.c_str());

    // 
    string registro = estado + " | " + fechaLog + " | UID: " + uid + " | Foto: " + "fotos/" + subCarpeta + nombreFoto;
    string cmdLog = "ssh " + USER_PI + "@" + IP_PI + " \"echo '" + registro + "' >> " + RUTA_PI + "log.txt\""; //usamos echo como en el examen :)
    system(cmdLog.c_str());

    remove(nombreFoto.c_str());

    cout << ">LISTO PAh<" << endl;
}

int main() {
    //                      abre            velocidad    no interpreta -echo(no lo veo)
    string configSerial = "stty -F " + puerto + " 115200 raw -echo";
    system(configSerial.c_str());


    FILE* serial = fopen(puerto.c_str(), "r+");
    if (!serial) {
        cerr << "ERROR: No se pudo abrir el Arduino."<< endl;
        return 1;
    }

    cout << ">>> GATE AUDIT<<<" << endl;

    char UIDS[50];
                //destino - limite, archivo
    while (fgets(UIDS,sizeof(UIDS), serial)) {
        string uidLeido(UIDS);
        
        uidLeido.erase(remove(uidLeido.begin(), uidLeido.end(), '\n'), uidLeido.end());
        uidLeido.erase(remove(uidLeido.begin(), uidLeido.end(), '\r'), uidLeido.end()); //arduino manda esto
        
        if (uidLeido.length() > 1) {
            if (estaEnWhitelist(uidLeido)) {
                procesarAcceso(uidLeido, true, serial);
            } else {
                procesarAcceso(uidLeido, false, serial);
            }
        }
        usleep(100000); // 0.1s, microsegundos
    }

    fclose(serial);
    return 0;
}

