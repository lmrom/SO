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

string construirIpDesdePrefijo(const string& prefijo, const string& ultimoOcteto) {
    if (!prefijo.empty() && prefijo.back() == '.') {
        return prefijo + ultimoOcteto;
    }
    return prefijo + "." + ultimoOcteto;
}

//CONFIGURACIONES DE IP
const string PREFIJO_RED_DEFAULT = "10.138.89.";
const string PREFIJO_RED_ALT = "10.97.7.";
const string IP_PI_DEFAULT      = construirIpDesdePrefijo(PREFIJO_RED_DEFAULT, "78");
const string IP_PI_ALT          = construirIpDesdePrefijo(PREFIJO_RED_ALT, "78");
const string IP_ESP32_DEFAULT   = construirIpDesdePrefijo(PREFIJO_RED_DEFAULT, "38");
const string IP_ESP32_ALT       = construirIpDesdePrefijo(PREFIJO_RED_ALT, "38");
const string USER_PI    = "lumr";
const string RUTA_PI    = "/var/www/html/"; 
const string puertoPreferido = "/dev/ttyACM0";
const int ID_PUERTA = 1;
const string TIPO_ACCESO = "ENTRADA";
const string API_TOKEN = "";
const string API_URL_DEFAULT = "http://" + IP_PI_DEFAULT + "/acceso.php";

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

string trim(const string& input) {
    size_t start = input.find_first_not_of(" \t\n\r");
    if (start == string::npos) return "";
    size_t end = input.find_last_not_of(" \t\n\r");
    return input.substr(start, end - start + 1);
}

vector<string> construirListaUnica(const vector<string>& in) {
    vector<string> out;
    for (const string& v : in) {
        string t = trim(v);
        if (t.empty()) continue;
        if (find(out.begin(), out.end(), t) == out.end()) {
            out.push_back(t);
        }
    }
    return out;
}

vector<string> obtenerIpsPi() {
    const char* envPi = getenv("GATE_PI_IP");
    if (envPi != nullptr) {
        string ip = trim(envPi);
        if (!ip.empty()) return {ip};
    }
    return construirListaUnica({IP_PI_DEFAULT, IP_PI_ALT});
}

vector<string> obtenerIpsEsp32() {
    const char* envEsp = getenv("GATE_ESP32_IP");
    if (envEsp != nullptr) {
        string ip = trim(envEsp);
        if (!ip.empty()) return {ip};
    }
    return construirListaUnica({IP_ESP32_DEFAULT, IP_ESP32_ALT});
}

vector<string> obtenerApiUrls() {
    const char* envUrl = getenv("GATE_API_URL");
    if (envUrl != nullptr) {
        string url = trim(envUrl);
        if (!url.empty()) return {url};
    }
    vector<string> urls;
    for (const string& ipPi : obtenerIpsPi()) {
        urls.push_back("http://" + ipPi + "/acceso.php");
    }
    if (urls.empty()) {
        urls.push_back(API_URL_DEFAULT);
    }
    return construirListaUnica(urls);
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

string postApiConFallback(const string& postArgs, int timeoutSeg = 7) {
    string lastResponse;
    for (const string& apiUrl : obtenerApiUrls()) {
        string cmdApi = "curl -sS --max-time " + to_string(timeoutSeg) +
                        " -X POST " + shellEscape(apiUrl) + " " + postArgs;
        string respuesta = ejecutarComandoYLeerSalida(cmdApi);
        if (!respuesta.empty()) {
            return respuesta;
        }
        lastResponse = respuesta;
    }
    return lastResponse;
}

bool ejecutarSshConFallback(const string& remoteCmd, int& outRc) {
    int lastRc = 1;
    for (const string& ipPi : obtenerIpsPi()) {
        string destino = USER_PI + "@" + ipPi;
        string cmd = "ssh " + SSH_OPCIONES + " " + shellEscape(destino) +
                     " " + shellEscape(remoteCmd);
        int rc = system(cmd.c_str());
        if (rc == 0) {
            outRc = 0;
            return true;
        }
        lastRc = rc;
    }
    outRc = lastRc;
    return false;
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
    string postArgs = "--data-urlencode " + shellEscape("uid=" + uid) +
                      " -d " + shellEscape("id_puerta=" + to_string(ID_PUERTA)) +
                      " -d " + shellEscape("tipo=" + TIPO_ACCESO);

    if (!API_TOKEN.empty()) {
        postArgs += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = postApiConFallback(postArgs, 7);
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

    string postArgs = "-d " + shellEscape("accion=ESTADO_REVISION") +
                      " -d " + shellEscape("id_registro=" + to_string(idRegistro));
    if (!API_TOKEN.empty()) {
        postArgs += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = postApiConFallback(postArgs, 7);
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
    string postArgs = "-d " + shellEscape("accion=RESOLVER_REVISION") +
                      " -d " + shellEscape("id_registro=" + to_string(idRegistro)) +
                      " -d " + shellEscape("decision=" + decision) +
                      " --data-urlencode " + shellEscape("revisor=" + revisor);
    if (!API_TOKEN.empty()) {
        postArgs += " -d " + shellEscape("token=" + API_TOKEN);
    }
    string respuesta = postApiConFallback(postArgs, 7);
    return respuesta.find("\"ok\":true") != string::npos;
}

bool existeArchivoRemoto(const string& rutaAbsRemota) {
    int rc = 1;
    return ejecutarSshConFallback("test -s " + shellEscape(rutaAbsRemota), rc);
}

bool adjuntarFotoEnApi(int idRegistro, const string& fotoRelativa) {
    if (idRegistro <= 0 || fotoRelativa.empty() || fotoRelativa == "SIN_FOTO") return false;

    string postArgs = "-d " + shellEscape("accion=ADJUNTAR_FOTO") +
                      " -d " + shellEscape("id_registro=" + to_string(idRegistro)) +
                      " --data-urlencode " + shellEscape("foto_url=" + fotoRelativa);

    if (!API_TOKEN.empty()) {
        postArgs += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = postApiConFallback(postArgs, 7);
    return respuesta.find("\"ok\":true") != string::npos;
}

bool marcarFalloAutoEnApi(int idRegistro, const string& motivo) {
    if (idRegistro <= 0) return false;

    string postArgs = "-d " + shellEscape("accion=MARCAR_FALLO_AUTO") +
                      " -d " + shellEscape("id_registro=" + to_string(idRegistro)) +
                      " --data-urlencode " + shellEscape("motivo=" + motivo);

    if (!API_TOKEN.empty()) {
        postArgs += " -d " + shellEscape("token=" + API_TOKEN);
    }

    string respuesta = postApiConFallback(postArgs, 7);
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
    vector<string> endpointsFoto;
    for (const string& ipEsp32 : obtenerIpsEsp32()) {
        endpointsFoto.push_back("http://" + ipEsp32 + "/capture");
        endpointsFoto.push_back("http://" + ipEsp32 + "/");
    }
    for (const string& urlFoto : endpointsFoto) {
        string cmdFoto = "curl -fsS --http1.0 -H " + shellEscape("Connection: close") +
                         " --connect-timeout 2 --max-time 15 --retry 2 --retry-delay 1 --retry-all-errors " +
                         shellEscape(urlFoto) + " -o " + shellEscape(nombreFoto);
        int rcFoto = system(cmdFoto.c_str());
        fotoLista = filesystem::exists(nombreFoto) && filesystem::file_size(nombreFoto) > 0;
        if (!fotoLista && rcFoto != 0) {
            remove(nombreFoto.c_str());
        }
        if (fotoLista && rcFoto != 0) {
            cerr << "WARN: curl reporto error, pero la foto se capturo correctamente. Se reutiliza imagen valida." << endl;
        }
        if (fotoLista) break;
    }

    // envio
    if (fotoLista) {
        cout << "Enviando foto a RP" << endl;
        int rcMkdir = 1;
        (void)ejecutarSshConFallback("mkdir -p " + RUTA_PI + "fotos/" + subCarpeta, rcMkdir);
        string rutaFotoRemotaAbs = RUTA_PI + "fotos/" + subCarpeta + nombreFoto;
        int rcEnviar = 1;
        for (const string& ipPi : obtenerIpsPi()) {
            string destinoFoto = USER_PI + "@" + ipPi + ":" + rutaFotoRemotaAbs;
            string cmdEnviarFoto = "scp -q " + SSH_OPCIONES + " " +
                                   shellEscape(nombreFoto) + " " + shellEscape(destinoFoto);
            rcEnviar = system(cmdEnviarFoto.c_str());
            if (rcEnviar == 0) break;
        }
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
            if (requiereRevision && idRegistro > 0) {
                bool marcado = marcarFalloAutoEnApi(idRegistro, "FOTO_NO_CONFIRMADA_EN_RASPBERRY");
                if (!marcado) {
                    cerr << "WARN: No se pudo marcar fallo de AUTO en API." << endl;
                }
            }
        }
    } else {
        cerr << "WARN: No se pudo capturar foto desde ESP32, se continua sin imagen." << endl;
        if (requiereRevision && idRegistro > 0) {
            bool marcado = marcarFalloAutoEnApi(idRegistro, "CAPTURA_FOTO_FALLIDA_ESP32");
            if (!marcado) {
                cerr << "WARN: No se pudo marcar fallo de AUTO en API." << endl;
            }
        }
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
    int rcLog = 1;
    bool logOk = ejecutarSshConFallback(cmdRemoto, rcLog);
    if (!logOk) {
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
