# openevse-charge-controller
PHP script to manage EV car charging via OpenEVSE / EmonEVSE charge points. Integrates to Domoticz/MQTT and manages solar/excess energy diversion to car battery in place of EmonEVSE software function.

I found the OpenEVSE software failed to start charging the car and allow >1kW of excess energy to spill back into the grid when it did start. This appears to be related to the car rather than the software, which does not respond accurately to the J1772 pilot signal. The software is open-loop and expects the car to track the signal properly so it does not function as expected.

This script works around the issue by making a closed loop system that responds to the measured excess grid energy to adjust the pilot signal and does not rely on the pilot signal tracking accuracy. It can also simply turn on/off charging at two different power levels. The script is contrlled by MQTT and will integrate to Domoticz this way using a simple 4 level switch device with (off, 10%, 20%, 30%) settings.

It uses my phpSyslog and phpMQTT classes from my repos.
