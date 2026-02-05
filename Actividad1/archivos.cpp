#include <iostream>
#include <fstream> 
#include <string>

using namespace std;

int main() {
    string name = "write.txt";
    string texto = "";
    ofstream archivo("write.txt");

    if (archivo.is_open()) {
        cout << "Ingresa el texto que deseas añadir: ";
        archivo.close();
        cout << "Archivo creado y guardado con exito.\n";
    } else {
        cout << "Error al crear el archivo.\n";
    }

    ofstream edit("write.txt", ios::app); 

    if (edit.is_open()) {
        getline(cin, texto);
        edit << texto;
        edit.close();
        cout << "Archivo editado y guardado correctamente.\n";
    }

  
    ifstream file("write.txt");
    string line;

    cout << "\n--- Contenido final del archivo ---\n";
    if (file.is_open()) {
        while (getline(file, line)) {
            cout << line << endl;
        }
        file.close();
    }

    return 0;
}