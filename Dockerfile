FROM glpi/glpi:11.0.4

# Instalar extensões PHP necessárias
USER root

# Descobrir versão do PHP e instalar
# RUN apt-get update && \
#     apt-get install -y \
#         php-redis \
#         build-essential \
#         php-dev \
#     || (pecl install redis && \
#         echo "extension=redis.so" >> $(php --ini | grep "Loaded Configuration" | sed -e "s|.*:\s*||") && \
#         echo "extension=redis.so" >> /etc/php/*/cli/php.ini) \
#     && rm -rf /var/lib/apt/lists/*

# Voltar ao usuário original  
USER www-data