#include <iostream>
#include <string>
#include <ctime> //fecha y hora
#include <cstdlib> // system() - bin/sh
#include <unistd.h> //usleep - t fork()
#include <algorithm> //manejo de strings
#include <cstdio> //archivos
#include <cctype>
#include <array>
#include <filesystem>
#include <vector>


using namespace std;

//CONFIGURACIONES DE IP
const string IP_PI      = "10.248.161.78";
const string IP_ESP32   = "10.248.161.38";
const string USER_PI    = "lumr";
const string RUTA_PI    = "/var/www/html/"; 
const string puertoPreferido = "/dev/ttyACM0";
const int ID_PUERTA = 1;
const string TIPO_ACCESO = "ENTRADA";
const string API_TOKEN = "";
const string API_URL = "http://" + IP_PI + "/acceso.php";

const string SSH_OPCIONES = "-o BatchMode=yes -o ConnectTimeout=5 -o PreferredAuthentications=publickey -o StrictHostKeyChecking=accept-new";
const int REVISION_TIMEOUT_SEG = 45;
const int REVISION_POLL_MS = 1000;


string shellEscape(const string& input) {
    string out = "'";
    for (char c : input) {
        if (c == '\'') out += "'\\''";
        else out += c;
    }
    out += "'";
    return out;
}

string ejecutarComandoYLeerSalida(const string& cmd) {
    array<char, 256> buffer{};
    string salida;
    FILE* pipe = popen(cmd.c_str(), "r");
    if (!pipe) return "";
    while (fgets(buffer.data(), static_cast<int>(buffer.size()), pipe)) {
        salida += buffer.data();
    }
    pclose(pipe);
    return salida;
}

string extraerCampoTextoJson(const string& json, const string& campo) {
    string clave = "\"" + campo + "\":\"";
    size_t inicio = json.find(clave);
    if (inicio == string::npos) return "";
    inicio += clave.size();
    size_t fin = json.find('"', inicio);
    if (fin == string::npos) return "";
    return json.substr(inicio, fin - inicio);
}

int extraerCampoEnteroJson(const string& json, const string& campo) {
    string clave = "\"" + campo + "\":";
    size_t inicio = json.find(clave);
    if (inicio == string::npos) return -1;
    inicio += clave.size();
    while (inicio < json.size() && isspace(static_cast<unsigned char>(json[inicio]))) inicio++;
    size_t fin = inicio;
    while (fin < json.size() && isdigit(static_cast<unsigned char>(json[fin]))) fin++;
    if (fin == inicio) return -1;
    try {
        return stoi(json.substr(inicio, fin - inicio));
    } catch (...) {
        return -1;
    }
}

bool extraerCampoBoolJson(const string& json, const string& campo, bool& valor) {
    string clave = "\"" + campo + "\":";
    size_t inicio = json.find(clave);
    if (inicio == string::npos) return false;
    inicio += clave.size();
    while (inicio < json.size() && isspace(static_cast<unsigned char>(json[inicio]))) inicio++;

    if (json.compare(inicio, 4, "true") == 0) {
        valor = true;
        return true;
    }
    if (json.compare(inicio, 5, "false") == 0) {
        valor = false;
        return true;
    }
    return false;
}

string detectarPuertoSerial() {
    if (filesystem::exists(puertoPreferido)) return puertoPreferido;

    for (int i = 0; i <= 9; i++) {
        string puerto = "/dev/ttyACM" + to_string(i);
        if (filesystem::exists(puerto)) return puerto;
    }
    for (int i = 0; i <= 9; i++) {
        string puerto = "/dev/ttyUSB" + to_string(i);
        if (filesystem::exists(puerto)) return puerto;
    }
    return "";
}

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


bool consultarApiAcceso(const string& uid, bool& autorizado, bool& requiereRevision, string& motivo, int& idRegistro) {
    idRegistro = -1;
    requiereRevision = false;
    string cmdApi = "curl -sS --max-time 7 -X POST " + shellEscape(API_URL) +
                    " --data-urlencode " + shellEscape("uid=" + uid) +
                    " -d " + shellEscape("id_puerta=" + to_string(ID_PUERTA)) +
                    " -d " + shellEscape("tipo=" + TIPO_ACCESO);

    if (!API_TOKEN.empty()) {
        cmdApi += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = ejecutarComandoYLeerSalida(cmdApi);
    if (respuesta.empty()) {
        autorizado = false;
        motivo = "SIN_RESPUESTA_API";
        return false;
    }

    bool okApi = (respuesta.find("\"ok\":true") != string::npos);
    if (!okApi) {
        autorizado = false;
        requiereRevision = false;
        motivo = extraerCampoTextoJson(respuesta, "motivo");
        if (motivo.empty()) motivo = "RESPUESTA_INVALIDA_API";
        return false;
    }

    if (!extraerCampoBoolJson(respuesta, "autorizado", autorizado)) {
        autorizado = false;
    }
    extraerCampoBoolJson(respuesta, "requiere_revision", requiereRevision);
    motivo = extraerCampoTextoJson(respuesta, "motivo");
    idRegistro = extraerCampoEnteroJson(respuesta, "id_registro");
    if (motivo.empty()) motivo = requiereRevision ? "REVISION_PENDIENTE" : (autorizado ? "AUTORIZADO" : "DENEGADO");
    return true;
}

bool consultarEstadoRevision(int idRegistro, bool& finalizada, bool& autorizadoFinal, string& revisionEstado, string& motivoRevision) {
    finalizada = false;
    autorizadoFinal = false;
    revisionEstado.clear();
    motivoRevision.clear();
    if (idRegistro <= 0) return false;

    string cmdApi = "curl -sS --max-time 7 -X POST " + shellEscape(API_URL) +
                    " -d " + shellEscape("accion=ESTADO_REVISION") +
                    " -d " + shellEscape("id_registro=" + to_string(idRegistro));
    if (!API_TOKEN.empty()) {
        cmdApi += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = ejecutarComandoYLeerSalida(cmdApi);
    if (respuesta.empty()) return false;
    if (respuesta.find("\"ok\":true") == string::npos) return false;

    extraerCampoBoolJson(respuesta, "finalizada", finalizada);
    extraerCampoBoolJson(respuesta, "autorizado_final", autorizadoFinal);
    revisionEstado = extraerCampoTextoJson(respuesta, "revision_estado");
    motivoRevision = extraerCampoTextoJson(respuesta, "motivo");
    if (revisionEstado.empty()) revisionEstado = "PENDIENTE";
    return true;
}

bool resolverRevisionEnApi(int idRegistro, const string& decision, const string& revisor) {
    if (idRegistro <= 0) return false;
    string cmdApi = "curl -sS --max-time 7 -X POST " + shellEscape(API_URL) +
                    " -d " + shellEscape("accion=RESOLVER_REVISION") +
                    " -d " + shellEscape("id_registro=" + to_string(idRegistro)) +
                    " -d " + shellEscape("decision=" + decision) +
                    " --data-urlencode " + shellEscape("revisor=" + revisor);
    if (!API_TOKEN.empty()) {
        cmdApi += " -d " + shellEscape("token=" + API_TOKEN);
    }
    string respuesta = ejecutarComandoYLeerSalida(cmdApi);
    return respuesta.find("\"ok\":true") != string::npos;
}

bool existeArchivoRemoto(const string& rutaAbsRemota) {
    string destino = USER_PI + "@" + IP_PI;
    string cmd = "ssh " + SSH_OPCIONES + " " + shellEscape(destino) +
                 " " + shellEscape("test -s " + shellEscape(rutaAbsRemota));
    int rc = system(cmd.c_str());
    return rc == 0;
}

bool adjuntarFotoEnApi(int idRegistro, const string& fotoRelativa) {
    if (idRegistro <= 0 || fotoRelativa.empty() || fotoRelativa == "SIN_FOTO") return false;

    string cmdApi = "curl -sS --max-time 7 -X POST " + shellEscape(API_URL) +
                    " -d " + shellEscape("accion=ADJUNTAR_FOTO") +
                    " -d " + shellEscape("id_registro=" + to_string(idRegistro)) +
                    " --data-urlencode " + shellEscape("foto_url=" + fotoRelativa);

    if (!API_TOKEN.empty()) {
        cmdApi += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = ejecutarComandoYLeerSalida(cmdApi);
    return respuesta.find("\"ok\":true") != string::npos;
}


void procesarAcceso(const string& uid, bool autorizadoPreliminar, bool requiereRevision, const string& motivoApi, int idRegistro, FILE* serial) {
    string fechaArchivo = obtenerFechaArchivo();
    string fechaLog = obtenerFechaLog();   
   
    bool autorizadoFinal = autorizadoPreliminar;
    string motivoFinal = motivoApi;

    string estadoInicial = requiereRevision
        ? "REVISION_PENDIENTE"
        : (autorizadoPreliminar ? "AUTORIZADO" : "DENEGADO");
    string subCarpeta = requiereRevision
        ? "revision/"
        : (autorizadoPreliminar ? "autorizado/" : "denegado/");
    string nombreFoto = "foto_" + fechaArchivo + ".jpg";
    string fotoRelativaRegistro = "SIN_FOTO";

    cout << "\n[" << fechaLog << "] Tarjeta: " << uid << " [" << estadoInicial << "]" << endl;
    cout << "Motivo API: " << motivoApi << endl;
    cout << "Procesando ..."<< endl;

    // foto
    cout << ">> Capturando foto" << endl;
    bool fotoLista = false;
    vector<string> endpointsFoto = {
        "http://" + IP_ESP32 + "/capture",
        "http://" + IP_ESP32 + "/"
    };
    for (const string& urlFoto : endpointsFoto) {
        string cmdFoto = "curl -fsS --http1.0 -H " + shellEscape("Connection: close") +
                         " --connect-timeout 2 --max-time 15 --retry 2 --retry-delay 1 --retry-all-errors " +
                         shellEscape(urlFoto) + " -o " + shellEscape(nombreFoto);
        int rcFoto = system(cmdFoto.c_str());
        fotoLista = (rcFoto == 0) && filesystem::exists(nombreFoto) && filesystem::file_size(nombreFoto) > 0;
        if (fotoLista) break;
    }

    // envio
    if (fotoLista) {
        cout << "Enviando foto a RP" << endl;
        string cmdCrearCarpeta = "ssh " + SSH_OPCIONES + " " + shellEscape(USER_PI + "@" + IP_PI) +
                                 " " + shellEscape("mkdir -p " + RUTA_PI + "fotos/" + subCarpeta);
        (void)system(cmdCrearCarpeta.c_str());

        string rutaFotoRemotaAbs = RUTA_PI + "fotos/" + subCarpeta + nombreFoto;
        string destinoFoto = USER_PI + "@" + IP_PI + ":" + rutaFotoRemotaAbs;
        string cmdEnviarFoto = "scp -q " + SSH_OPCIONES + " " +
                               shellEscape(nombreFoto) + " " + shellEscape(destinoFoto);
        int rcEnviar = system(cmdEnviarFoto.c_str());
        bool fotoConfirmadaEnPi = (rcEnviar == 0) && existeArchivoRemoto(rutaFotoRemotaAbs);
        if (fotoConfirmadaEnPi) {
            fotoRelativaRegistro = "fotos/" + subCarpeta + nombreFoto;
            cout << "Foto enviada OK: " << fotoRelativaRegistro << endl;
            if (idRegistro > 0) {
                bool fotoAdjunta = adjuntarFotoEnApi(idRegistro, fotoRelativaRegistro);
                if (!fotoAdjunta) {
                    cerr << "WARN: La foto se subio, pero no se pudo adjuntar al registro " << idRegistro << " en API." << endl;
                }
            } else {
                cerr << "WARN: La foto se subio, pero no hay id_registro valido para adjuntarla en DB." << endl;
            }
        } else {
            cerr << "WARN: No se pudo confirmar la foto en la Raspberry. Ruta esperada: "
                 << rutaFotoRemotaAbs << endl;
        }
    } else {
        cerr << "WARN: No se pudo capturar foto desde ESP32, se continua sin imagen." << endl;
    }

    if (requiereRevision) {
        cout << "Esperando decision manual en el panel..." << endl;
        bool revisionFinalizada = false;
        string estadoRevision;

        if (idRegistro <= 0) {
            autorizadoFinal = false;
            motivoFinal = "SIN_ID_REVISION";
            cerr << "WARN: No hay id_registro para revisar manualmente." << endl;
        } else {
            for (int i = 0; i < REVISION_TIMEOUT_SEG; i++) {
                bool okRevision = consultarEstadoRevision(idRegistro, revisionFinalizada, autorizadoFinal, estadoRevision, motivoFinal);
                if (okRevision && revisionFinalizada) {
                    break;
                }
                usleep(REVISION_POLL_MS * 1000);
            }

            if (!revisionFinalizada) {
                autorizadoFinal = false;
                motivoFinal = "REVISION_TIMEOUT_DENEGADO";
                bool resolvio = resolverRevisionEnApi(idRegistro, "DENEGAR", "AUTO_TIMEOUT_CPP");
                if (!resolvio) {
                    cerr << "WARN: Timeout de revision y no se pudo cerrar automaticamente en API." << endl;
                }
            } else if (motivoFinal.empty()) {
                motivoFinal = autorizadoFinal ? "APROBADO_DESPUES_REVISION" : "DENEGADO_DESPUES_REVISION";
            }
        }
    }

    string estado = autorizadoFinal ? "AUTORIZADO" : "DENEGADO";
    if (autorizadoFinal) {
        fputc('1', serial);
        cout << "Serial TX => '1'" << endl;
    } else {
        fputc('2', serial);
        cout << "Serial TX => '2'" << endl;
    }
    fflush(serial); // Fuerza la salida inmediata

    // Log auxiliar de texto (ademas de la BD)
    string registro = estado + " | " + fechaLog + " | UID: " + uid + " | Motivo: " + motivoFinal +
                      " | Foto: " + fotoRelativaRegistro;
    string cmdRemoto = "echo " + shellEscape(registro) + " | tee -a " + RUTA_PI + "log.txt > /dev/null";
    string cmdLog = "ssh " + SSH_OPCIONES + " " + shellEscape(USER_PI + "@" + IP_PI) + " " + shellEscape(cmdRemoto);
    int rcLog = system(cmdLog.c_str());
    if (rcLog != 0) {
        cerr << "WARN: No se pudo escribir log remoto en Raspberry (ssh no interactivo)." << endl;
    }

    if (filesystem::exists(nombreFoto)) {
        remove(nombreFoto.c_str());
    }

    cout << ">LISTO PAh<" << endl;
}

int main() {
    string puerto = detectarPuertoSerial();
    if (puerto.empty()) {
        cerr << "ERROR: No se detecto Arduino en /dev/ttyACM* ni /dev/ttyUSB*." << endl;
        cerr << "Conecta el Arduino y vuelve a ejecutar." << endl;
        return 1;
    }

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
            bool autorizado = false;
            bool requiereRevision = false;
            string motivoApi;
            int idRegistro = -1;

            bool apiOk = consultarApiAcceso(uidLeido, autorizado, requiereRevision, motivoApi, idRegistro);
            if (!apiOk && motivoApi.empty()) {
                motivoApi = "ERROR_API";
            }

            procesarAcceso(uidLeido, autorizado, requiereRevision, motivoApi, idRegistro, serial);
        }
        usleep(100000); // 0.1s, microsegundos
    }

    fclose(serial);
    return 0;
}
