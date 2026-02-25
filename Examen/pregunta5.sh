   99  sudo cmake --install build
  100  cd ~/.local/share/caelestia/plugin
  101  calestia
  102  ~/.local/share/caelestia/install.fish
  103  ls
  104  sudo pacman -S python-hatch
  105  sudo pacman -S python-hatch-vcs
  106  git clone https://github.com/caelestia-dots/cli.git
  107  git clone https://github.com/caelestia-dots/cli.git
  108  cd cli
  109  ls
  110  cd ..
  111  ls
  112  rm -rf cli
  113  ls
  114  cd
  115  cli
  116  ls
  117  cd cli
  118  python -m build --wheel
  119  sudo python -m installer dist/*.whl
  120  sudo cp completions/caelestia.fish /usr/share/fish/vendor_completions.d/caelestia.fish
  121  calestia -h
  122  cd
  123  calestia -h
  124  cd $XDG_CONFIG_HOME/quickshell
  125  cd cli
  126  cd $XDG_CONFIG_HOME/quickshell
  127  ls
  128  cd
  129  fzf
  130  fzf
  131  cd .config/quickshell/
  132  ls
  133  ls -la
  134  # Instala lo necesario para compilar
  135  sudo pacman -S --needed cmake extra-cmake-modules qt6-base qt6-declarative
  136  # Ve a la carpeta del plugin e intenta compilar
  137  cd ~/.local/share/caelestia/plugin
  138  mkdir -p build && cd build
  139  cmake ..
  140  make
  141  sudo make install
  142  # Crea la carpeta de módulos de Quickshell si no existe
  143  mkdir -p ~/.local/share/quickshell/modules
  144  # Crea un enlace del módulo Caelestia hacia allá
  145  ln -sf ~/.local/share/caelestia/plugin/src/Caelestia ~/.local/share/quickshell/modules/
  146  ln -sf ~/.local/share/caelestia/plugin/src/Caelestia ~/.local/share/quickshell/modules/
  147  ls
  148  ./CMakeFiles
  149  cd ~/.local/share/caelestia/plugin
  150  fxf
  151  fzf
  152  cd
  153  cd shell
  154  ls
  155  cd Caelestia
  156  cmake -B build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/
  157  cmake --build build
  158  sudo cmake --install build
  159  mkdir -p ~/.config/quickshell/caelestia
  160  cmake -B build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/ -DINSTALL_QSCONFDIR=~/.config/quickshell/caelestia
  161  cmake --build build
  162  sudo cmake --install build
  163  sudo chown -R $USER ~/.config/quickshell/caelestia
  164  cd
  165  mkdir -p ~/.config/quickshell/caelestia
  166  clear
  167  cmake -B build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/ -DINSTALL_QSCONFDIR=~/.config/quickshell/caelestia
  168  ls
  169  cd cli
  170  ls
  171  cd ..
  172  cd shell
  173  cd
  174  ls
  175  cd shell
  176  ls
  177  cmake -B build -G Ninja -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/ -DINSTALL_QSCONFDIR=~/.config/quickshell/caelestia
  178  cmake --build build
  179  sudo cmake --install build
  180  sudo cmake --install build
  181  # Crea la carpeta por si no existe (sin sudo para que sea tuya)
  182  mkdir -p ~/.config/quickshell/caelestia
  183  # Copia los assets desde donde descargaste el repositorio
  184  cp -r ~/shell/assets ~/.config/quickshell/caelestia/
  185  cp -r ~/shell/assets ~/.config/quickshell/caelestia/
  186  quickshell -p ~/.local/share/caelestia/shell.qml
  187  sudo quickshell -p ~/.local/share/caelestia/shell.qml
  188  QML_IMPORT_PATH=/usr/lib/qt6/qml quickshell -p ~/.local/share/caelestia/shell.qml
  189  sudo quickshell -p ~/.local/share/caelestia/shell.qml
  190  sudo cmake --install build
  191  fzf
  192  fzf
  193  sudo chown -R $USER ~/.config/quickshell/caelestia
  194  sudo cmake --install build
  195  calestia shell -d
  196  ls
  197  assets
  198  cd assets
  199  ls
  200  sudo cmake --install build
  201  cd ..
  202  sudo cmake --install build
  203  mkdir -p ~/.config/quickshell/caelestia
  204  cd ~/.local/share/caelestia/plugin
  205  cd ..
  206  cd
  207  cd ~/.local/share/caelestia/plugin
  208  find ~/.local/share/caelestia -name "CMakeLists.txt"
  209  fzf
  210  cd ~/shell/plugin 
  211  # O si no existe ahí:
  212  cd ~/.local/share/caelestia/src/plugin
  213  cd ~/shell/plugin 
  214  # Bajamos el plugin oficial
  215  git clone https://github.com/Caelestia-Shell/Caelestia.git ~/caelestia-plugin
  216  git clone https://github.com/Caelestia-Shell/Caelestia.git ~/caelestia-plugin
  217  git clone https://github.com/Caelestia-Shell/Caelestia.git ~/caelestia-plugin
  218  git clone https://github.com/caelestia-dots/shell/tree/main/plugin ~/caelestia-plugin
  219  cd ..
  220  ls
  221  cd ~/caelestia-source/plugin
  222  cd
  223  cd ~/caelestia-source/plugin
  224  kitty
  225  sudo pacman -S rpi-imager
  226  clear
  227  rpi-imager
  228  sudo rpi-imager
  229  nmap -sn  187.190.39.213
  230  ifconfig
  231  nmap -sn  127.0.0.1
  232  hostname -I
  233  nmcli device show
  234  sudo rpi-connect
  235  sudo pacman -S rpi-connect
  236  ssh lmr@ifconfig
  237  ifconfig
  238  ssh lmr@10.13.160.19
  239  sudo nmap -sn 192.168.1.0/24
  240  ssh lmr@127.0.0.1
  241  hostname -I
  242  sudo nmap -sn 192.168.1.0/24
  243  sudo nmap 192.168.0.0/24
  244  ssh lmr@lmrpi.local
  245  pinng raspberrypi.local
  246  ssh lmr@lmrpi.local
  247  ssh lmr@10.13.160.78
  248  ssh -i ~/.ssh/id_rsa lmr@raspberrypi.local
  249  ssh lmr@raspberrypi.local
  250  ssh lmr@rlmrpi.local
  251  sudo systemctl enable --now avahi-daemon
  252  ssh lmr@rlmrpi.local
  253  ls
  254  rm instal.sh
  255  ls
  256  cd SO
  257  ls
  258  nano segcasi.cpp
  259  g++ segcasi.cpp -o segcasi
  260  ls
  261  segcasi
  262  ./segcasi
  263  ./segcasi
  264  ls
  265  nano segcasi
  266  ssh lmr@10.13.160.78
  267  lsusb
  268  ssh-keygen
  269  ssh-copy-id lmr@10.13.160.78
  270  cd
  271  cd SO
  272  ls
  273  sudo pacman -Syu
  274  clear
  275  ssh lmr@rlmrpi.local
  276  ssh lmr@10.13.160.78
  277  clear
  278  ./segcasi
  279  ssh lmr@10.13.160.78
  280  xlear
  281  clear
  282  ls
  283  nano segcasi.cpp
  284  g++ segcasi.cpp -o segcasi
  285  ls
  286  ./segcasi
  287  nano segcasi.cpp
  288  g++ segcasi.cpp -o segcasi
  289  ./segcasi
  290  sudo chown lmr:www-data /var/www/html/log.txt
  291  sudo chmod 664 /var/www/html/log.txt
  292  ./segcasi
  293  nano
  294  nano segcasi.cpp
  295  nano segcasi.cpp
  296  g++ segcasi.cpp -o segcasi
  297  ./segcasi
  298  ping -c 4 10.13.160.38
  299  curl -v http://10.13.160.38/capture -o prueba.jpg
  300  curl -v http://10.13.160.38/capture -o prueba.jpg
  301  # Intenta capturar de nuevo, pero sin el -v para ver si termina
  302  curl -s http://10.13.160.38/capture -o prueba2.jpg && ls -lh prueba2.jpg
  303  ls
  304  ls -lh prueba2.jpg
  305  nano segcasi.cpp
  306  nano segcasi.cpp
  307  clear
  308  ./segcasi
  309  nano segcasi.cpp
  310  ls
  311  nano segcasi.cpp
  312  rm segcasi.cpp
  313  nano segcasi.cpp
  314  la
  315  ls
  316  rm seg1 se1.1 seg1.1.cpp segcasi seg.cpp seguridad1.2.cpp
  317  rm *.cpp
  318  rm
  319  rm seg1
  320  ls
  321  rm seg1.1
  322  nano sistema.cpp
  323  g++ sistema.cpp -o sistem
  324  ./sistem
  325  nano seguridad.cpp
  326  ls
  327  nano sistema.cpp
  328  cd
  329  wget
  330  wget https://hetmanrecovery.com/download/linux/hetman_partition_recovery.install
  331  sudo wget https://hetmanrecovery.com/download/linux/hetman_partition_recovery.install
  332  sh /home/clearlmr/Downloads/hetman_partition_recovery.install 
  333  clear
  334  cd SO
  335  ls
  336  nano sistema.cpp
  337  rm sistema.cpp
  338  nano sistema.cpp
  339  g++ sistema.cpp -o sistem
  340  ./sistem
  341  ./sistem
  342  ls
  343  rm sistema.cpp
  344  nano sistema.cpp
  345  g++ sistema.cpp -o sistem
  346  ./sistem
  347  ./sistem
  348  ./sistem
  349  ./sistem
  350  echo '1' > /dev/ttyACM0
  351  echo '2' > /dev/ttyACM0
  352  nano seguridad.cpp
  353  cd
  354  ls
  355  cd SO
  356  nano sistema.cpp
  357  g++ sistema.cpp -o sistem
  358  ./sistem
  359  ./sistem
  360  ls
  361  ls
  362  ls
  363  g++ segcasi.cpp -o segcasi
  364  ./segcasi
  365  nano prueba.cpp
  366  g++ prueba.cpp -o prueba
  367  ./prueba
  368  nano prueba.cpp
  369  ./prueba
  370  g++ prueba.cpp -o prueba
  371  nano prueba.cpp
  372  g++ prueba.cpp -o prueba
  373  ./prueba
  374  ls
  375  nano prueba.cpp
  376  g++ prueba.cpp -o prueba
  377  ./prueba
  378  sudo pacman -S vscode
  379  vscode
  380  ssh lmr@10.13.160.78
  381  ssh lmr@10.13.160.78
  382  ssh lmr@10.13.160.78
  383  clear
  384  ls
  385  ssh lmr@10.13.160.78
  386  g++ prueba.cpp -o prueba
  387  ./prueba
  388  g++ prueba.cpp -o prueba
  389  g++ prueba.cpp -o prueba
  390  g++ prueba.cpp -o prueba
  391  ./prueba
  392  ls
  393  rm segcasi
  394  rm prueba.jpg
  395  rm siste a.cpp
  396  rm sistema.cpp
  397  rm segcasi.cpp
  398  ls
  399  rm prueba
  400  rm prueba2.jpg
  401  rm sistem
  402  ls
  403  g++ prueba.cpp -o sistema
  404  ./prueba
  405  ./sistema
  406  ls
  407  g++ prueba.cpp -o sistema
  408  ./sistema
  409  ./sistema
  410  ./sistema
  411  ./sistema
  412  ./sistema
  413  nano segcasi.cpp
  414  ls
  415  cd SO
  416  nano segcasi.cpp
  417  sudo pacman -S git wget curl make cmake nano vim
  418  clea
  419  sudo pacman -S fish
  420  git clone https://github.com/caelestia-dots/caelestia.git ~/.local/share/caelestia
  421  cd .local/share
  422  ls
  423  rm -rf caelestia
  424  ls
  425  git clone https://github.com/caelestia-dots/caelestia.git ~/.local/share/caelestia
  426  cd caelestia
  427  ls
  428  ./ install.fish
  429  ~/.local/share/caelestia/install.fish
  430   sudo pacman -S libreoffice-fresh gwenview kate dolphin ark okular flatpack discover discord swayimg 
  431   sudo pacman -S libreoffice-fresh gwenview kate dolphin ark okular flatpak discover discord swayimg 
  432   sudo pacman -S bluez bluez-utils sddm
  433  sudo systemcl enable bluetooth
  434  sudo systemctl enable bluetooth
  435  sudo systemctl enable sddm
  436  sudo systemctl start sddm
  437  ls
  438  rm -rf cli
  439  ls
  440  rm -rf shell
  441  cd Templates
  442  ls
  443  ls -la
  444  cd
  445  hostname -I
  446  curl ifconfig.me
  447  ssh lmr@187.190.39.213
  448  ssh lmr@187.190.39.213
  449  ping raspberrypi.local
  450  sudo pacman -S avahi
  451  sudo systemctl enable --now avahi-daemon
  452  ping raspberrypi.local
  453  sudo nano /etc/nsswitch.conf
  454  ping raspberrypi.local
  455  ip -br addr
  456  sudo nmap -sn 10.13.160.19/24
  457  ssh lmr@10.13.160.246
  458  ssh lmr@10.13.160.19
  459  ping raspberrypi.local
  460  sudo ping raspberrypi.local
  461  ssh lmr@lmrpi
  462  nmap -sn 192.168.101.0/
  463  ssh lmr@lmrpi
  464  ssh lmr@lmrpi.local
  465  ssh lmr@10.13.160.78
  466  ls
  467  g++ prueba.cpp -o sistema
  468  ./sistema
  469  ./sistema
  470  fzf
  471  rm | grep calestia
  472  clear
  473  sudo pacman -Rns caelestia-shell caelestia-cli caelestia-shell-git
  474  rm -rf ~/.config/caelestia ~/.config/hypr ~/.config/foot ~/.config/fish ~/.config/uwsm ~/.local/share/caelestia
  475  rm -rf ~/.config/caelestia ~/.config/hypr ~/.config/foot ~/.config/fish ~/.config/uwsm ~/.local/share/caelestia
  476  rm -rf ~/.config/caelestia ~/.config/hypr ~/.config/foot ~/.config/fish ~/.config/uwsm ~/.local/share/caelestia
  477  rm -rf ~/.cache/caelestia
  478  sudo pacman -Sc
  479  sudo pacman -Rs $(pacman -Qtdq)
  480  sudo pacman -Rns caelestia-shell caelestia-cli && rm -rf ~/.config/caelestia ~/.local/share/caelestia ~/.cache/caelestia
  481  fzf
  482  sudo find / -iname "*caelestia*" 2>/dev/null
  483  sudo find / -iname "*caelestia*" -exec rm -rf {} + 2>/dev/null
  484  fzf
  485  cd SO
  486  ls
  487  cd
  488  sudo pacman -Syu
  489  clear
  490  sudo pacman -S git wget curl gcc make cmake nano vim
  491  sudo pacman -S fish
  492  git clone https://github.com/caelestia.git ~/.local/share/caelestia
  493  git clone https://github.com/caelestia-dots/caelestia.git ~/.local/share/caelestia
  494   ~/.local/share/caelestia/install.fish
  495  cd SO
  496  ls
  497  ./sistema
  498  ls
  499  cat whitelist.txt
  500  ./sistema
  501  ls
  502  SO
  503  ls
  504  SO
  505  cd SO
  506  git clone https://github.com/lmrom/SO
  507  ls
  508  git add .
  509  git add.
  510  ls
  511  rm -rf SO
  512  git init
  513  ls
  514  mkdir Proyecto
  515  mv prueba.cpp sistema whitelist.txt Proyecto
  516  ls
  517  git remote add origin https://github.com/lmrom/SO
  518  git branch main -M
  519  git push -u origin main 
  520  git add.
  521  git add Proyecto
  522  git push
  523  git oush --set-upstream otigin main
  524  git help
  525  git commit Proyecto
  526  git config --global user.email luis.an.mromero@gmail.com
  527  git config --global user.name lmrom
  528  git commit Proyecto
  529  ls
  530  git hel
  531  git help
  532  git add Proyecto
  533  git commit
  534  git push
  535  git push --set-upstream origin main
  536  git branch -M main
  537  git push -u origin main
  538  git init
  539  git remote add origin https://github.com/lmrom/SO
  540  git add .
  541  git commit -m Proyecto
  542  git push -u origin main
  543  git push -u origin main
  544  git push -u origin main
  545  git pull
  546  ls
  547  git pull origin main --rebase
  548  ls
  549  git commit -m Proyecto
  550  git push -u origin main
  551  git push -u origin main
  552  ls
  553  ls
  554  cd /etc; ls | grep cron
  555  echo hola
  556  echo
  557  echo hijodetuputamadre
  558  echo soyelmencho
  559  nano /.bashrc
  560  ls
  561  cd
  562  nano ~/.bashrc
  563  echo "alias code=codium" >> .bashrc
  564  code
  565  find .bashrc
  566  echo $PATH
  567  whatis export
  568  export
  569  clear
  570  ls
  571  lsd
  572  lds
  573  lsd
  574  lsd
  575  tldr
  576  fastfetch
  577  w
  578  updatetime
  579  updte-time
  580  update-time
  581  ls
  582  lsd
  583  uptime
  584  clear
  585  ls
  586  cd SO
  587  mkdir
  588  ls
  589  history grep | ls
  590  history | grep ls
  591  false
  592  history | grep ls
  593  history | grep -w ls | wc 
  594  history | grep -w ls | wc -l
  595  touch accept.txt; ls > accept.txt && history | grep ls | wc -l  >> accept.txt
  596  nano pregunta5.sh
  597  history
  598  history >> pregunta5.sh
