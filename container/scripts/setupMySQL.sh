#!/bin/bash
source /opt/stateless/engine/phvalheim.conf

#WTF is this nonsense?!

#MariaDB from Ubuntu's repo is busted (see bug https://bugs.launchpad.net/ubuntu/+source/mariadb-10.6/+bug/1970634)
#apt-get install --no-install-recommends --no-install-suggests -y mariadb-server mariadb-backup


#Grab the patched version manually until the repo is updated.
function installCustomMaria(){
	echo "Downloading MariaDB-Server..."
	wget -O /tmp/libmpfr6_4.1.0-3build3_amd64.deb http://launchpadlibrarian.net/592807171/libmpfr6_4.1.0-3build3_amd64.deb
	wget -O /tmp/mariadb-common_10.6.7-2ubuntu1.1_all.deb http://launchpadlibrarian.net/610725674/mariadb-common_10.6.7-2ubuntu1.1_all.deb
	wget -O /tmp/mariadb-client-core-10.6_10.6.7-2ubuntu1.1_amd64.deb http://launchpadlibrarian.net/610725706/mariadb-client-core-10.6_10.6.7-2ubuntu1.1_amd64.deb
	wget -O /tmp/mariadb-client-10.6_10.6.7-2ubuntu1.1_amd64.deb http://launchpadlibrarian.net/610725705/mariadb-client-10.6_10.6.7-2ubuntu1.1_amd64.deb
	wget -O /tmp/libmariadb3_10.6.7-2ubuntu1.1_amd64.deb http://launchpadlibrarian.net/610725702/libmariadb3_10.6.7-2ubuntu1.1_amd64.deb
	wget -O /tmp/mysql-common_5.8+1.0.8_all.deb http://launchpadlibrarian.net/583207132/mysql-common_5.8+1.0.8_all.deb
	wget -O /tmp/libconfig-inifiles-perl_3.000003-1_all.deb http://launchpadlibrarian.net/478704951/libconfig-inifiles-perl_3.000003-1_all.deb
	wget -O /tmp/galera-4_26.4.9-1build1_amd64.deb http://launchpadlibrarian.net/570407338/galera-4_26.4.9-1build1_amd64.deb
	wget -O /tmp/libsigsegv2_2.13-1ubuntu3_amd64.deb http://launchpadlibrarian.net/592800978/libsigsegv2_2.13-1ubuntu3_amd64.deb
	wget -O /tmp/libdbi-perl_1.643-3build3_amd64.deb http://launchpadlibrarian.net/584577339/libdbi-perl_1.643-3build3_amd64.deb
	wget -O /tmp/lsof_4.93.2+dfsg-1.1build2_amd64.deb http://launchpadlibrarian.net/592949892/lsof_4.93.2+dfsg-1.1build2_amd64.deb
	wget -O /tmp/libwrap0_7.6.q-31build2_amd64.deb http://launchpadlibrarian.net/592986039/libwrap0_7.6.q-31build2_amd64.deb
	wget -O /tmp/socat_1.7.4.1-3ubuntu4_amd64.deb http://launchpadlibrarian.net/592959836/socat_1.7.4.1-3ubuntu4_amd64.deb
	wget -O /tmp/libnuma1_2.0.14-3ubuntu2_amd64.deb http://launchpadlibrarian.net/592807192/libnuma1_2.0.14-3ubuntu2_amd64.deb
	wget -O /tmp/libpmem1_1.11.1-3build1_amd64.deb http://launchpadlibrarian.net/592810915/libpmem1_1.11.1-3build1_amd64.deb
	wget -O /tmp/libsnappy1v5_1.1.8-1build3_amd64.deb http://launchpadlibrarian.net/592958924/libsnappy1v5_1.1.8-1build3_amd64.deb
	wget -O /tmp/libdaxctl1_72.1-1_amd64.deb http://launchpadlibrarian.net/582012250/libdaxctl1_72.1-1_amd64.deb
	wget -O /tmp/libndctl6_72.1-1_amd64.deb http://launchpadlibrarian.net/582012251/libndctl6_72.1-1_amd64.deb
	wget -O /tmp/liburing2_2.1-2build1_amd64.deb http://launchpadlibrarian.net/592804929/liburing2_2.1-2build1_amd64.deb
	wget -O /tmp/mariadb-server-core-10.6_10.6.7-2ubuntu1.1_amd64.deb http://launchpadlibrarian.net/610725720/mariadb-server-core-10.6_10.6.7-2ubuntu1.1_amd64.deb
	wget -O /tmp/mariadb-server-10.6_10.6.7-2ubuntu1.1_amd64.deb http://launchpadlibrarian.net/610725719/mariadb-server-10.6_10.6.7-2ubuntu1.1_amd64.deb

	echo "Manually installing MariaDB packages..."
	yes N | dpkg -i /tmp/*.deb
	rm -rf /tmp/*.deb
	sleep 2
}


#Install MariaDB from repo
function installRepoMaria(){
	echo "Installing MariaDB from Repo..."
	apt-get install -y mysql-server
	sleep 2
}


#installRepoMaria
#installCustomMaria

#Config prep
#rm /etc/mysql/mysql.cnf /etc/mysql/my.cnf
#touch /etc/mysql/mysql.cnf /etc/mysql/my.cnf
#echo "[mysqld]" >> /etc/mysql/my.cnf
#echo "datadir=/opt/stateful/mysql/data" >> /etc/mysql/my.cnf
#echo "tmpdir=/opt/stateful/mysql/temp" >> /etc/mysql/my.cnf
#cat /etc/mysql/my.cnf > /etc/mysql/mysql.cnf

#Set root password
SQL "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'secretpassword';"
SQL "ALTER USER 'debian-sys-maint'@'localhost' IDENTIFIED WITH mysql_native_password BY '6eNEue1WGUlBXBXG';"
