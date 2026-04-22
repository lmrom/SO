FROM rockylinux:9

WORKDIR /app

RUN dnf upgrade -y &&\
  dnf install gcc gcc-c++ make cmake -y 

COPY . . 

RUN mkdir build && cd build &&\
  cmake .. && \
  make 

CMD ["bash"] 
