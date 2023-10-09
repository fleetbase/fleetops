<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\Flow;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Category;
use Fleetbase\Models\Extension;
use Fleetbase\Models\ExtensionInstall;
use Fleetbase\Models\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderConfigController extends Controller
{
    /**
     * Retrieve all installed or created order configurations.
     *
     * @return \Illuminate\Http\Response
     */
    public function getInstalled(Request $request)
    {
        $key                 = $request->input('key');
        $namespace           = $request->input('namespace');
        $single              = $request->input('single', false);
        $installedExtensions = [];

        // get all installed order configs
        $installed = ExtensionInstall::where('company_uuid', session('company'))
            ->whereHas(
                'extension',
                function ($query) {
                    $query->where('meta_type', 'order_config');
                }
            )
            ->with('extension')->get();

        // morph installed into extensions
        foreach ($installed as $install) {
            $installedExtensions[] = $install->asExtension();
        }

        // get authored extension installs
        $authored = Extension::where(['author_uuid' => session('company'), 'meta_type' => 'order_config', 'status' => 'private'])->get();

        // create array of configs
        $configs = collect([...$installedExtensions, ...$authored]);

        // if no installed configs always place default config
        if ($configs->isEmpty()) {
            $configs = $configs->merge(Flow::getAllDefaultOrderConfigs());
        }

        // filter by key
        if ($key) {
            $configs = $configs->where('key', $key);
        }

        // filter by namespace
        if ($namespace) {
            $configs = $configs->where('namespace', $namespace);
        }

        if ($single) {
            return response()->json($configs->first());
        }

        return response()->json($configs->values()->toArray());
    }

    /**
     * Save an order extension, whether installed or authored.
     *
     * @return \Illuminate\Http\Response
     */
    public function save(Request $request)
    {
        $data        = $request->input('data', []);
        $isInstalled = isset($data['installed']) && Utils::isTrue($data['installed']);

        // if the extension is from installed
        if ($isInstalled) {
            // get the extension install record
            $install = ExtensionInstall::where('uuid', $data['install_uuid'])->first();

            // update install record
            $overwrite                = $install->overwrite ?? [];
            $overwrite['name']        = $data['name'];
            $overwrite['description'] = $data['description'];

            // update json fields
            $install->overwrite = $overwrite;
            $install->meta      = $data['meta'];

            // save changes
            $install->save();

            return response()->json(['status' => 'OK']);
        }

        // get the extension record
        $extension = Extension::where('uuid', $data['uuid'])->first();

        // update extension record
        $extension->name        = $data['name'];
        $extension->description = $data['description'];
        $extension->meta        = $data['meta'];

        // save
        $extension->save();

        return response()->json(['status' => 'OK']);
    }

    /**
     * Creates a new empty order configuration.
     *
     * @return \Illuminate\Http\Response
     */
    public function new(Request $request)
    {
        $name        = $request->input('name');
        $description = $request->input('description');
        $tags        = $request->input('tags', []);

        $company  = Flow::getCompanySession();
        $category = Category::where(['for' => 'extension', 'name' => 'Logistics'])->first();
        $type     = Type::where(['for' => 'extension', 'key' => 'config'])->first();

        $orderConfig = Extension::create(
            [
                'author_uuid'   => session('company'),
                'category_uuid' => $category->uuid,
                'type_uuid'     => $type->uuid,
                'name'          => $name,
                'description'   => $description,
                'display_name'  => $name,
                'key'           => Str::slug($name),
                'tags'          => $tags,
                'namespace'     => Extension::createNamespace($company->slug, 'order-config', $name),
                'version'       => '0.0.1',
                'core_service'  => 0,
                'meta'          => ['flow' => Flow::getDefaultOrderFlow()],
                'meta_type'     => 'order_config',
                'config'        => [],
                'status'        => 'private',
            ]
        );

        return response()->json($orderConfig);
    }

    /**
     * Clones an order configuration into a new configuration.
     *
     * @return \Illuminate\Http\Response
     */
    public function clone(Request $request)
    {
        $name        = $request->input('name');
        $description = $request->input('description');
        $id          = $request->input('id');
        $installed   = $request->input('installed', false);
        $company     = Flow::getCompanySession();

        if (!$id) {
            return response()->json(
                [
                    'errors' => ['Extension attempted to clone not found'],
                ],
                400
            );
        }

        // if the extension is from installed
        if ($installed) {
            // get the extension install record
            $install = ExtensionInstall::where('uuid', $id)->first();

            // replicate the install
            $clonedInstall            = $install->replicate();
            $clonedInstall->id        = null;
            $clonedInstall->uuid      = Extension::generateUuid();
            $clonedInstall->overwrite = [
                'name'         => $name,
                'display_name' => $name,
                'description'  => $description,
                'key'          => Str::slug($name),
                'namespace'    => Extension::createNamespace($company->slug, 'order-config', $name, Str::random(5)),
            ];

            // save clone
            $clonedInstall->save();

            return response()->json($clonedInstall->asExtension());
        }

        // get the extension record
        $extension = Extension::where('uuid', $id)->first();

        // replicate the extension record
        $clonedExtension               = $extension->replicate();
        $clonedExtension->id           = null;
        $clonedExtension->uuid         = Extension::generateUuid();
        $clonedExtension->public_id    = Extension::generatePublicId('ext');
        $clonedExtension->name         = $name;
        $clonedExtension->display_name = $name;
        $clonedExtension->description  = $description;
        $clonedExtension->key          = Str::slug($name);
        $clonedExtension->namespace    = Extension::createNamespace($company->slug, 'order-config', $name, Str::random(5));
        $clonedExtension->status       = 'private';

        // save clone
        $clonedExtension->save();

        return response()->json($clonedExtension);
    }

    /**
     * Pull all dynamically created meta fields throughout orders of a specific type.
     *
     * @return \Illuminate\Http\Response
     */
    public function getDynamicMetaFields(Request $request)
    {
        $type          = $request->input('type', 'transport');
        $orders        = Order::select('meta')->where('type', $type)->whereNull('deleted_at')->get();
        $dynamicFields = collect();

        foreach ($orders as $order) {
            $dynamicFields = $dynamicFields->merge(array_keys($order->meta));
        }

        return response()->json($dynamicFields->unique()->values());
    }

    /**
     * Pull all dynamically created meta fields throughout orders of a specific type.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(string $id)
    {
        $extension = Extension::where(['uuid' => $id, 'author_uuid' => session('company'), 'meta_type' => 'order_config'])->first();

        if ($extension) {
            $extension->delete();

            return response()->json(['status' => 'OK', 'deleted' => $extension->uuid]);
        }

        $installedExtension = ExtensionInstall::where(['uuid' => $id, 'company_uuid' => session('company')])->first();

        if ($installedExtension) {
            $installedExtension->delete();

            return response()->json(['status' => 'OK', 'deleted' => $installedExtension->uuid]);
        }

        return response()->json(
            [
                'errors' => 'Unable to uninstall order configuration',
            ],
            400
        );
    }
}
