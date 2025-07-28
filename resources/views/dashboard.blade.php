<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Welcome to Mossfield Organic Farm Management</h3>
                    <p class="mb-6">You're logged in! Here are some quick actions to get started:</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                            <h4 class="font-semibold text-blue-800 mb-2">Product Management</h4>
                            <p class="text-sm text-blue-700 mb-3">Manage your milk, yoghurt, and cheese products</p>
                            <a href="{{ route('products.index') }}" class="inline-block bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                                View Products
                            </a>
                        </div>
                        
                        <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                            <h4 class="font-semibold text-green-800 mb-2">Batch Tracking</h4>
                            <p class="text-sm text-green-700 mb-3">Track production batches and traceability</p>
                            <button class="inline-block bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm opacity-50 cursor-not-allowed">
                                Coming Soon
                            </button>
                        </div>
                        
                        <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                            <h4 class="font-semibold text-yellow-800 mb-2">Order Management</h4>
                            <p class="text-sm text-yellow-700 mb-3">Manage customer orders and deliveries</p>
                            <button class="inline-block bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded text-sm opacity-50 cursor-not-allowed">
                                Coming Soon
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
