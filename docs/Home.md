The [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) Viera Connector is an extension for the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem that enables seamless integration
with Panasonic Viera televisions. It allows users to easily connect and control Panasonic televisions from within the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem,
providing a simple and user-friendly interface for managing and monitoring your televisions.

# Naming Convention

The connector uses the following naming convention for its entities:

## Connector

A connector is an entity that manages communication with Panasonic Viera televisons. It needs to be configured for a specific device interface.

## Device

A device is an entity that represents a physical Panasonic Viera television.

# Configuration

To use Panasonic Viera televisions with the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem, you will need to configure at least one connector.
The connector can be configured using the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) user interface or through the console.

## Configuring the Connector through the Console

To configure the connector through the console, run the following command:

```shell
php bin/fb-console fb:viera-connector:initialize
```

> **NOTE:**
The path to the console command may vary depending on your FastyBird application distribution. For more information, refer to the FastyBird documentation.

The console will ask you to confirm that you want to continue with the configuration.

```shell
Viera connector - initialization
================================

 ! [NOTE] This action will create|update|delete connector configuration.                                                       

 Would you like to continue? (yes/no) [no]:
 > y
```

You will then be prompted to choose an action:

```shell
 What would you like to do?:
  [0] Create new connector configuration
  [1] Edit existing connector configuration
  [2] Delete existing connector configuration
 > 0
```

You will then be asked to provide a connector identifier and name:

```shell
 Provide connector identifier:
 > my-viera
```

```shell
 Provide connector name:
 > My Viera
```

After providing the necessary information, your new Panasonic Viera connector will be ready for use.

```shell
 [OK] New connector "My Viera" was successfully created                                                                
```

## Configuring the Connector with the FastyBird User Interface

You can also configure the Panasonic Viera connector using the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) user interface. For more information on how to do this,
please refer to the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) documentation.

## TV setup

Before you could pair your television with connector, you have to prepare your television.

On your TV go to `Menu -> Network -> TV Remote App Settings` and make sure that the following settings are all turned ON:

* TV Remote
* Powered On by Apps
* Networked Standby

Then, go to `Menu -> Network -> Network Status -> Status Details` and take note of your TV ip address.

# Devices Discovery

The Panasonic Viera connector includes a built-in feature for automatic device discovery. This feature can be triggered manually
through a console command or from the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) user interface.

## Manual Console Command

To manually trigger device discovery, use the following command:

```shell
php bin/fb-console fb:viera-connector:discover
```

> **NOTE:**
The path to the console command may vary depending on your FastyBird application distribution. For more information, refer to the FastyBird documentation.

The console will prompt for confirmation before proceeding with the discovery process.

```shell
Viera connector - discovery
===========================

 ! [NOTE] This action will run connector devices discovery.

 Would you like to continue? (yes/no) [no]:
 > y
```

You will then be prompted to select the connector to use for the discovery process.

```shell
 Would you like to discover devices with "My Viera" connector (yes/no) [no]:
 > y
```

The connector will then begin searching for new Panasonic Viera devices, which may take a few minutes to complete. Once finished,
a list of found devices will be displayed.

```shell
 [INFO] Starting Viera connector discovery...

[============================] 100% 36 secs/36 secs %

 [INFO] Found 2 new televisions                                                                                         


+---+--------------------------------------+----------------+------------------------------+------------+------------+
| # | ID                                   | Name           | Model                        | IP address | Encryption |
+---+--------------------------------------+----------------+------------------------------+------------+------------+
| 1 | 10513b70-944c-4a90-aa37-b5a08ffdcac4 | 65FX700_Series | Panasonic VIErA TX-65FX700E  | 10.10.0.6  | yes        |
| 2 | 6ed9d4ae-45b3-4081-a14f-e48f1f49dc30 | 49DX600_Series | Panasonic VIErA TX-49DX600EA | 10.10.0.75 | no         |
+---+--------------------------------------+----------------+------------------------------+------------+------------+

 [INFO] Some televisions require to by paired to get encryption keys

 Would you like to pair this televisions? (yes/no) [no]:
 > y                                                    
```

Now that all newly discovered devices have been found, they are available in the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) system and can be utilized.

If some televisions require PIN code authentication you will be prompted if you want to pair these televisions. If you do not finish this pairing you will be not able to fully control this televisions.

```shell
 [INFO] Pairing television: 65FX700_Series
```

Your television have to be turned on and on the screen you will find (in bottom left corner) pairing PIN code.

```shell
 Provide television PIN code displayed on you TV:
 > 1234
 
 [OK] Television 65FX700_Series was successfully paired

 [OK] Televisions discovery was successfully finished
```

# Devices Configuration

If you do not want to use automatic televisions discovery, you could configure your televisions manually.
This can be accomplished either through a console command or through the user interface of the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things).

## Manual Console Command

To manually trigger device configuration, use the following command:

```shell
php bin/fb-console fb:viera-connector:devices
```

> **NOTE:**
The path to the console command may vary depending on your FastyBird application distribution. For more information, refer to the FastyBird documentation.

The console will prompt for confirmation before proceeding with the devices configuration process.

```shell
Viera connector - televisions management
========================================

 ! [NOTE] This action will create|update|delete connector device.                                                       

 Would you like to continue? (yes/no) [no]:
 > y
```

You will then be prompted to select connector to manage devices.

```shell
 Please select connector under which you want to manage devices:
  [0] my-viera [My Viera]
 > 0
```

You will then be prompted to select device management action.

```shell
 What would you like to do?:
  [0] Create new connector device
  [1] Edit existing connector device
  [2] Delete existing connector device
 > 0
```

Now you will be asked to provide some device details:

```shell
 Provide television IP address:
 > 10.10.0.2
```

```shell
 Would you like to configure HDMI inputs? (yes/no) [no]:
 > y
```

If you want to configure HDMI inputs you could define one, two or all inputs what you television have

```shell
 ! [NOTE] Now you have to provide name for configured HDMI input and its number. HDMI number is related to you          
 !        television                                                                                                    

 Provide name for HDMI input:
 > HDMI 1
```

```shell
 Provide number for "HDMI 1" HDMI input:
 > 1
```

```shell
 Would you like to configure another HDMI input? (yes/no) [no]:
 > n
```
> **NOTE:**
You could configure all or only some HDMI inputs.

```shell
 Would you like to configure television MAC address? (yes/no) [no]:
 > y
```

> **NOTE:**
MAC address will be used for Wake on Lan action which will turn you television on.

```shell
 Provide television MAC address in format: 01:23:45:67:89:ab:
 > 28:24:ff:38:5e:27
```

If there are no errors, you will receive a success message.

```shell
 [OK] Television "49DX600_Series" was successfully created
```

If you television require PIN code authentication you will be asked to provide pin which will be showed on you television screen

```shell
 Provide television PIN code displayed on you TV:
 > 1234
```

When valid PIN code is provided, pairing will be successfully finished.

# Troubleshooting

## Discovery Issues

For automatic televisions discovery is used UPnP protocol, so in some cases this communication could be blocked on you network or device where you are running instance of [FastyBird](https://www.fastybird.com) application

## Using connector

This connector is using event bases communication with televisions therefore it will check and generate communication port which will be opened for direct communication with televisions.
So if you are using [FastyBird](https://www.fastybird.com) application in Docker, there could be issue with establishing this connection

## Turning on television

In some cases, some models could have problems to remotely turn on via command from connector. So this connector is supporting WoL (Wake on Lan). So you have to provide television MAC address.
You could find this address in you router clients info or is available also in network status in some televisions models.

This MAC address could be entered via console edit device command or via user interface.