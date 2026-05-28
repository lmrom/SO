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

/**
 * Recibe: Un prefijo de red (string) y el último octeto (string).
 * Hace: Concatena ambos para formar una dirección IP válida, asegurando que haya un punto de separación.
 * Devuelve: La dirección IP completa como string.
 */
string construirIpDesdePrefijo(const string& prefijo, const string& ultimoOcteto) {
    if (!prefijo.empty() && prefijo.back() == '.') {
        return prefijo + ultimoOcteto;
    }
    return prefijo + "." + ultimoOcteto;
}

//CONFIGURACIONES DE IP
const string PREFIJO_RED_DEFAULT = "10.63.184.";
const string IP_PI_DEFAULT      = construirIpDesdePrefijo(PREFIJO_RED_DEFAULT, "78");
const string IP_ESP32_DEFAULT   = construirIpDesdePrefijo(PREFIJO_RED_DEFAULT, "38");
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


/**
 * Recibe: Una cadena de texto (string).
 * Hace: Escapa las comillas simples para que la cadena sea segura al usarse en comandos de shell.
 * Devuelve: La cadena procesada envuelta en comillas simples.
 */
string shellEscape(const string& input) {
    string out = "'";
    for (char c : input) {
        if (c == '\'') out += "'\\''";
        else out += c;
    }
    out += "'";
    return out;
}

/**
 * Recibe: Una cadena de texto (string).
 * Hace: Elimina los espacios en blanco, tabulaciones y saltos de línea al inicio y al final.
 * Devuelve: La cadena limpia (trimmed).
 */
string trim(const string& input) {
    size_t start = input.find_first_not_of(" \t\n\r");
    if (start == string::npos) return "";
    size_t end = input.find_last_not_of(" \t\n\r");
    return input.substr(start, end - start + 1);
}

/**
 * Recibe: Un vector de strings con posibles duplicados o espacios.
 * Hace: Limpia cada string y genera una lista sin elementos repetidos ni vacíos.
 * Devuelve: Un vector de strings con valores únicos.
 */
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

/**
 * Recibe: Nada.
 * Hace: Intenta obtener la IP de la Raspberry Pi desde una variable de entorno o usa los valores por defecto.
 * Devuelve: Un vector de strings con las IPs disponibles.
 */
vector<string> obtenerIpsPi() {
    const char* envPi = getenv("GATE_PI_IP");
    if (envPi != nullptr) {
        string ip = trim(envPi);
        if (!ip.empty()) return {ip};
    }
    return construirListaUnica({IP_PI_DEFAULT});
}

/**
 * Recibe: Nada.
 * Hace: Intenta obtener la IP del ESP32 desde una variable de entorno o usa los valores por defecto.
 * Devuelve: Un vector de strings con las IPs disponibles.
 */
vector<string> obtenerIpsEsp32() {
    const char* envEsp = getenv("GATE_ESP32_IP");
    if (envEsp != nullptr) {
        string ip = trim(envEsp);
        if (!ip.empty()) return {ip};
    }
    return construirListaUnica({IP_ESP32_DEFAULT});
}

/**
 * Recibe: Nada.
 * Hace: Construye las URLs de la API basándose en las IPs de la Raspberry Pi disponibles.
 * Devuelve: Un vector de strings con las URLs completas de la API.
 */
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

/**
 * Recibe: Un comando de sistema (string).
 * Hace: Ejecuta el comando mediante un pipe y captura su salida estándar.
 * Devuelve: El contenido de la salida del comando como string.
 */
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

/**
 * Recibe: Argumentos para el comando POST de curl (string) y un tiempo de espera (int).
 * Hace: Intenta realizar la petición a las diferentes URLs de la API hasta obtener una respuesta.
 * Devuelve: La respuesta del servidor como string o vacío si todas fallan.
 */
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

/**
 * Recibe: Un comando a ejecutar remotamente (string) y una referencia para el código de salida (int).
 * Hace: Intenta ejecutar el comando vía SSH en las IPs de la Raspberry Pi disponibles.
 * Devuelve: Booleano indicando si la ejecución fue exitosa (rc=0) en alguna de las IPs.
 */
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

/**
 * Recibe: Una cadena JSON (string) y el nombre de un campo (string).
 * Hace: Realiza un análisis manual básico para encontrar el valor de un campo de texto entre comillas.
 * Devuelve: El valor del campo como string.
 */
string extraerCampoTextoJson(const string& json, const string& campo) {
    string clave = "\"" + campo + "\":\"";
    size_t inicio = json.find(clave);
    if (inicio == string::npos) return "";
    inicio += clave.size();
    size_t fin = json.find('"', inicio);
    if (fin == string::npos) return "";
    return json.substr(inicio, fin - inicio);
}

/**
 * Recibe: Una cadena JSON (string) y el nombre de un campo (string).
 * Hace: Busca y extrae un valor numérico asociado a la clave proporcionada.
 * Devuelve: El valor como entero (int), o -1 si hay error.
 */
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

/**
 * Recibe: Una cadena JSON (string), el nombre de un campo (string) y una referencia para el valor (bool).
 * Hace: Busca el valor booleano 'true' o 'false' para la clave indicada.
 * Devuelve: Verdadero si se pudo extraer el campo con éxito, falso de lo contrario.
 */
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

/**
 * Recibe: Nada.
 * Hace: Escanea el sistema buscando dispositivos seriales activos (/dev/ttyACM* o /dev/ttyUSB*).
 * Devuelve: La ruta del puerto serial detectado o una cadena vacía si no encuentra ninguno.
 */
string detectarPuertoSerial() {
    const vector<string> rutasSerialPersistentes = {
        "/dev/serial/by-id",
        "/dev/serial/by-path"
    };
    for (const auto& dir : rutasSerialPersistentes) {
        if (!filesystem::exists(dir) || !filesystem::is_directory(dir)) continue;
        for (const auto& entry : filesystem::directory_iterator(dir)) {
            if (!entry.is_symlink() && !entry.is_character_file()) continue;
            string ruta = filesystem::canonical(entry.path()).string();
            if (ruta.rfind("/dev/ttyACM", 0) == 0 || ruta.rfind("/dev/ttyUSB", 0) == 0) {
                return ruta;
            }
        }
    }

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

/**
 * Recibe: Nada.
 * Hace: Obtiene la hora actual del sistema y la formatea para su uso en nombres de archivos.
 * Devuelve: Un string con formato "YYYY-MM-DD_HH-MM-SS".
 */
string obtenerFechaArchivo() {
    time_t ahora = time(0);
    struct tm tstruct;
    char buf[80];
    tstruct = *localtime(&ahora);
    strftime(buf, sizeof(buf), "%Y-%m-%d_%H-%M-%S", &tstruct);
    return string(buf);
}

/**
 * Recibe: Nada.
 * Hace: Obtiene la hora actual del sistema formateada para registros de log.
 * Devuelve: Un string con formato "DD/MM/YYYY HH:MM:SS".
 */
string obtenerFechaLog() {
    time_t ahora = time(0);
    struct tm tstruct;
    char buf[80];
    tstruct = *localtime(&ahora);
    strftime(buf, sizeof(buf), "%d/%m/%Y %H:%M:%S", &tstruct);
    return string(buf);
}


/**
 * Recibe: El UID de la tarjeta y referencias para autorización, revisión, motivo e ID de registro.
 * Hace: Realiza una consulta POST a la API para verificar si el acceso está permitido o requiere supervisión.
 * Devuelve: Booleano indicando si la comunicación con la API fue exitosa.
 */
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

/**
 * Recibe: El ID del registro y referencias para el estado de la revisión y motivos.
 * Hace: Consulta la API para saber si un operador ya tomó una decisión sobre un acceso pendiente.
 * Devuelve: Booleano indicando si la consulta a la API fue exitosa.
 */
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

/**
 * Recibe: ID del registro, decisión (aprobar/denegar) y nombre del revisor.
 * Hace: Envía a la API la resolución de una revisión manual (usualmente por timeout).
 * Devuelve: Booleano indicando si la operación fue exitosa en la API.
 */
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

/**
 * Recibe: Una ruta absoluta en el servidor remoto (string).
 * Hace: Ejecuta un comando SSH para verificar si el archivo existe y tiene tamaño mayor a cero.
 * Devuelve: Verdadero si el archivo existe en el remoto, falso si no.
 */
bool existeArchivoRemoto(const string& rutaAbsRemota) {
    int rc = 1;
    return ejecutarSshConFallback("test -s " + shellEscape(rutaAbsRemota), rc);
}

/**
 * Recibe: ID del registro y la ruta relativa de la foto (string).
 * Hace: Notifica a la API que se ha subido una foto para asociarla al registro de acceso.
 * Devuelve: Booleano indicando si la asociación fue exitosa.
 */
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

/**
 * Recibe: ID del registro y el motivo del fallo (string).
 * Hace: Informa a la API sobre un error automático (ej. fallo de cámara) durante el proceso.
 * Devuelve: Booleano indicando si la API registró el fallo correctamente.
 */
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


/**
 * Recibe: UID, datos preliminares de la API, ID de registro y el puntero al archivo serial.
 * Hace: Orquesta todo el flujo: captura fotos del ESP32, las sube a la Pi vía SCP, gestiona la espera de revisiones manuales y finalmente envía la orden de apertura o denegación al Arduino.
 * Devuelve: Nada (procedimiento de flujo).
 */
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

/**
 * Recibe: Nada.
 * Hace: Punto de entrada del programa. Configura el puerto serial, lo abre y entra en un bucle infinito escuchando las lecturas de UIDs del Arduino para procesar cada acceso.
 * Devuelve: 0 si termina correctamente, 1 si hay errores fatales de configuración.
 */
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
